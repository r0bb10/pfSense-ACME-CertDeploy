package daemon

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"certdeploy/internal/acme"
	"certdeploy/internal/config"
	"certdeploy/internal/deploy"
	"certdeploy/internal/pfsense"
	"certdeploy/internal/ssh"
	"certdeploy/internal/state"
)

// Daemon runs the CertDeploy background service.
type Daemon struct {
	cfg      *config.Config
	logger   *slog.Logger
	logFile  *logFile
	version  string
	state    *state.Manager
	ssh      *ssh.Runner
	engine   *deploy.Engine
	socket   string
	acme     *acme.Discoverer
	sem      chan struct{}
	mu       sync.Mutex
	inFlight map[string]struct{}
}

func parseLogLevel(level string) slog.Level {
	switch level {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

func newLogger(path, level string) (*slog.Logger, *logFile, error) {
	l, err := openLogFile(path, 0640)
	if err != nil {
		return nil, nil, err
	}
	handler := NewLogHandler(l, parseLogLevel(level))
	return slog.New(handler), l, nil
}

func New(cfg *config.Config, version string) (*Daemon, error) {
	lg, lf, err := newLogger(cfg.LogFile, cfg.LogLevel)
	if err != nil {
		return nil, fmt.Errorf("failed to create logger: %w", err)
	}

	stateMgr := state.NewManager(cfg.StateFile)
	runner := ssh.NewRunner(cfg, lg.With("component", "ssh"))
	engine := deploy.NewEngine(cfg, lg.With("component", "deploy"), stateMgr, runner)

	maxConcurrent := cfg.MaxConcurrentTargets
	if maxConcurrent < 1 {
		maxConcurrent = 1
	}

	d := &Daemon{
		cfg:      cfg,
		logger:   lg.With("component", "daemon"),
		logFile:  lf,
		version:  version,
		state:    stateMgr,
		ssh:      runner,
		engine:   engine,
		socket:   cfg.Socket,
		acme:     acme.New(cfg),
		sem:      make(chan struct{}, maxConcurrent),
		inFlight: make(map[string]struct{}),
	}

	if err := os.MkdirAll("/var/run/certdeploy", 0750); err != nil {
		return nil, err
	}
	return d, nil
}

func (d *Daemon) Run() error {
	if !d.cfg.Enable {
		d.logger.Info("certdeploy is disabled in configuration")
		return nil
	}

	d.logger.Info("certdeployd starting", slog.String("version", d.version))

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	if err := removeStaleSocket(d.socket); err != nil {
		return err
	}

	// Start control socket listener.
	ln, err := net.Listen("unix", d.socket)
	if err != nil {
		return fmt.Errorf("failed to listen on %s: %w", d.socket, err)
	}
	defer func() {
		if err := ln.Close(); err != nil {
			d.logger.Error("failed to close listener", slog.Any("error", err))
		}
	}()
	if err := os.Chmod(d.socket, 0600); err != nil {
		d.logger.Error("failed to chmod socket", slog.Any("error", err))
	}

	go d.acceptLoop(ctx, ln)

	// Start ACME scan loop.
	if d.cfg.ACMEMonitor {
		go d.scanLoop(ctx)
	}

	// Start reconciliation loop.
	if d.cfg.Reconcile {
		go d.reconcileLoop(ctx)
	}

	// Wait for shutdown signal.
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM, syscall.SIGUSR1)
	for sig := range sigCh {
		if sig == syscall.SIGUSR1 {
			d.logger.Debug("log rotation requested")
			if err := d.logFile.Reopen(); err != nil {
				d.logger.Error("failed to reopen log", slog.Any("error", err))
			}
			continue
		}
		break
	}

	cancel()
	d.logger.Info("shutting down")
	return d.logFile.Close()
}

func (d *Daemon) runDeployment(id string) {
	d.mu.Lock()
	if _, exists := d.inFlight[id]; exists {
		d.mu.Unlock()
		d.logger.Debug("deployment already queued or running", slog.String("deployment", id))
		return
	}
	d.inFlight[id] = struct{}{}
	d.mu.Unlock()
	defer func() {
		d.mu.Lock()
		delete(d.inFlight, id)
		d.mu.Unlock()
	}()

	d.sem <- struct{}{}
	defer func() { <-d.sem }()

	dep, err := pfsense.LoadDeployment(d.cfg.ConfigPath, id)
	if err != nil {
		d.logger.Error("failed to load deployment",
			slog.String("deployment", id), slog.Any("error", err))
		return
	}

	log := d.logger.With(slog.String("deployment", id), slog.String("name", dep.Name))

	var result deploy.RunResult
	for attempt := 0; ; attempt++ {
		result = d.engine.Run(context.Background(), dep)
		if result.Error == nil || attempt >= d.cfg.DefaultRetryCount {
			break
		}
		log.Warn("deployment failed; retrying",
			slog.Int("attempt", attempt+1), slog.Any("error", result.Error))
		time.Sleep(time.Duration(d.cfg.DefaultRetryDelay) * time.Second)
	}
	if result.Error != nil {
		log.Error("deployment failed", slog.Any("error", result.Error))
		return
	}
	d.logger.Debug("deployment succeeded", slog.String("deployment", id), slog.String("name", dep.Name))

	if result.HostKeyFingerprint != "" && result.HostKeyFingerprint != dep.HostKeyFingerprint {
		log.Info("host key fingerprint changed",
			slog.String("fingerprint", result.HostKeyFingerprint))
	}
}

func removeStaleSocket(path string) error {
	info, err := os.Lstat(path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("inspect control socket: %w", err)
	}
	if info.Mode()&os.ModeSocket == 0 {
		return fmt.Errorf("control socket path is not a socket: %s", path)
	}
	conn, err := net.DialTimeout("unix", path, time.Second)
	if err == nil {
		_ = conn.Close()
		return fmt.Errorf("control socket is already in use: %s", path)
	}
	if err := os.Remove(path); err != nil {
		return fmt.Errorf("remove stale control socket: %w", err)
	}
	return nil
}

func (d *Daemon) acceptLoop(ctx context.Context, ln net.Listener) {
	for {
		conn, err := ln.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			d.logger.Error("accept error", slog.Any("error", err))
			continue
		}
		go d.handleConn(ctx, conn)
	}
}

func (d *Daemon) handleConn(ctx context.Context, conn net.Conn) {
	defer func() {
		if err := conn.Close(); err != nil {
			d.logger.Error("failed to close connection", slog.Any("error", err))
		}
	}()
	dec := json.NewDecoder(conn)
	var req struct {
		Args []string `json:"args"`
	}
	if err := dec.Decode(&req); err != nil {
		_, _ = fmt.Fprintf(conn, "error: %v\n", err)
		return
	}
	if len(req.Args) == 0 {
		_, _ = fmt.Fprintln(conn, "error: no command")
		return
	}

	switch req.Args[0] {
	case "status":
		_, _ = fmt.Fprintln(conn, "running")
	case "scan-acme":
		go d.scanACME()
		_, _ = fmt.Fprintln(conn, "scan scheduled")
	case "deploy-outdated":
		go d.reconcile()
		_, _ = fmt.Fprintln(conn, "deploy outdated scheduled")
	case "deploy":
		if len(req.Args) < 2 {
			_, _ = fmt.Fprintln(conn, "error: deploy requires a deployment id")
			return
		}
		go d.runDeployment(req.Args[1])
		_, _ = fmt.Fprintln(conn, "deploy scheduled")
	default:
		_, _ = fmt.Fprintf(conn, "unknown command: %s\n", req.Args[0])
	}
}

func (d *Daemon) scanLoop(ctx context.Context) {
	ticker := time.NewTicker(time.Duration(d.cfg.ScanInterval) * time.Second)
	defer ticker.Stop()

	// Run once shortly after startup, then on ticker.
	time.AfterFunc(5*time.Second, func() { d.scanACME() })

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			d.scanACME()
		}
	}
}

func (d *Daemon) reconcileLoop(ctx context.Context) {
	ticker := time.NewTicker(time.Duration(d.cfg.ReconcileInterval) * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			d.reconcile()
		}
	}
}

// scanACME discovers ACME certs, compares fingerprints, and queues auto-deploy runs.
func (d *Daemon) scanACME() {
	d.logger.Debug("scanning ACME state")
	if err := d.state.UpdateLastScanTime(time.Now().Unix()); err != nil {
		d.logger.Error("failed to update last scan time", slog.Any("error", err))
	}

	ids, err := d.outdatedDeploymentIDs(false)
	if err != nil {
		d.logger.Error("ACME scan failed", slog.Any("error", err))
		return
	}

	for _, id := range ids {
		d.logger.Info("auto-deploying outdated deployment", slog.String("deployment", id))
		go d.runDeployment(id)
	}
}

// reconcile deploys all outdated certificates regardless of auto_deploy.
func (d *Daemon) reconcile() {
	d.logger.Debug("running reconciliation")
	if err := d.state.UpdateLastReconcileTime(time.Now().Unix()); err != nil {
		d.logger.Error("failed to update last reconcile time", slog.Any("error", err))
	}

	ids, err := d.outdatedDeploymentIDs(true)
	if err != nil {
		d.logger.Error("reconciliation failed", slog.Any("error", err))
		return
	}

	for _, id := range ids {
		d.logger.Info("reconciling outdated deployment", slog.String("deployment", id))
		go d.runDeployment(id)
	}
}

// outdatedDeploymentIDs returns deployment IDs whose current ACME certificate
// fingerprint differs from the last deployed fingerprint.
// If includeManualDeployments is true, all enabled certificate-backed deployments are returned.
// Otherwise, only deployments with auto_deploy enabled are returned.
func (d *Daemon) outdatedDeploymentIDs(includeManualDeployments bool) ([]string, error) {
	certs, err := d.acme.Discover()
	if err != nil {
		return nil, err
	}

	fpByName := make(map[string]string, len(certs))
	for _, c := range certs {
		if c.Status == "disabled" {
			continue
		}
		fpByName[c.Name] = c.Fingerprint
	}

	deployments, err := pfsense.LoadAllDeployments(d.cfg.ConfigPath)
	if err != nil {
		return nil, err
	}

	st, err := d.state.Load()
	if err != nil {
		return nil, err
	}

	var ids []string
	for _, dep := range deployments {
		if !dep.Enabled {
			continue
		}
		if dep.CertificateName == "" {
			continue
		}
		if !includeManualDeployments && !dep.AutoDeploy {
			continue
		}
		currentFP, ok := fpByName[dep.CertificateName]
		if !ok {
			d.logger.Warn("deployment references unknown ACME certificate",
				slog.String("deployment", dep.ID),
				slog.String("certificate", dep.CertificateName))
			continue
		}
		if currentFP == "" {
			continue
		}
		stored := st.Deployments[dep.ID].LastDeployedFingerprint
		if stored == "" || currentFP != stored {
			ids = append(ids, dep.ID)
		}
	}

	return ids, nil
}
