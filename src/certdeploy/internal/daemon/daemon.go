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

// acquireTarget serializes checks and pushes and shares the concurrency limit.
func (d *Daemon) acquireTarget(ctx context.Context, id string) func() {
	if ctx.Err() != nil {
		return nil
	}
	d.mu.Lock()
	if _, exists := d.inFlight[id]; exists {
		d.mu.Unlock()
		d.logger.Debug("deployment already queued or running", slog.String("deployment", id))
		return nil
	}
	d.inFlight[id] = struct{}{}
	d.mu.Unlock()
	releaseID := func() {
		d.mu.Lock()
		delete(d.inFlight, id)
		d.mu.Unlock()
	}
	select {
	case d.sem <- struct{}{}:
		if ctx.Err() != nil {
			<-d.sem
			releaseID()
			return nil
		}
		return func() { <-d.sem; releaseID() }
	case <-ctx.Done():
		releaseID()
		return nil
	}
}

func (d *Daemon) runDeployment(ctx context.Context, id string) {
	release := d.acquireTarget(ctx, id)
	if release == nil {
		return
	}
	defer release()

	dep, err := pfsense.LoadDeployment(d.cfg.ConfigPath, id)
	if err != nil {
		d.logger.Error("failed to load deployment",
			slog.String("deployment", id), slog.Any("error", err))
		return
	}

	log := d.logger.With(slog.String("deployment", id), slog.String("name", dep.Name))

	var result deploy.RunResult
	for attempt := 0; ; attempt++ {
		result = d.engine.Run(ctx, dep)
		if result.Error == nil || attempt >= d.cfg.DefaultRetryCount {
			break
		}
		log.Warn("deployment failed; retrying",
			slog.Int("attempt", attempt+1), slog.Any("error", result.Error))
		if !waitRetry(ctx, d.cfg.DefaultRetryDelay) {
			return
		}
	}
	if result.Error != nil {
		log.Error("deployment failed", slog.Any("error", result.Error))
		return
	}
	d.logger.Debug("deployment succeeded", slog.String("deployment", id), slog.String("name", dep.Name))

	if result.HostKeyFingerprint != "" && result.HostKeyFingerprint != result.TrustedHostKeyFingerprint {
		log.Info("host key fingerprint changed",
			slog.String("fingerprint", result.HostKeyFingerprint))
	}
	d.verifyTarget(ctx, dep)
}

func (d *Daemon) runVerification(ctx context.Context, id string) {
	release := d.acquireTarget(ctx, id)
	if release == nil {
		return
	}
	defer release()
	dep, err := pfsense.LoadDeployment(d.cfg.ConfigPath, id)
	if err != nil {
		d.logger.Error("failed to load verification target", slog.Any("error", err))
		return
	}
	if dep.Enabled {
		d.verifyTarget(ctx, dep)
	}
}

func (d *Daemon) verifyTarget(ctx context.Context, dep deploy.Deployment) {
	if !dep.VerifyFiles {
		return
	}
	log := d.logger.With("deployment", dep.ID, "name", dep.Name)
	var result state.FileVerification
	var fingerprint string
	for attempt := 0; ; attempt++ {
		if ctx.Err() != nil {
			return
		}
		result, fingerprint = d.engine.Verify(ctx, dep)
		if ctx.Err() != nil {
			return
		}
		if result.Status == "verified" || result.Status == "not_configured" || attempt >= d.cfg.DefaultRetryCount {
			break
		}
		log.Warn("file verification failed; retrying", slog.Any("error", result.Error))
		if !waitRetry(ctx, d.cfg.DefaultRetryDelay) {
			return
		}
	}
	if err := d.state.UpdateFileVerification(dep.ID, result, fingerprint); err != nil {
		log.Error("failed to save file verification", slog.Any("error", err))
		return
	}
	if result.Status == "verified" {
		log.Debug("remote files verified")
	} else if result.Status != "not_configured" {
		log.Warn("file verification failed", slog.Any("error", result.Error))
	}
}

func waitRetry(ctx context.Context, seconds int) bool {
	timer := time.NewTimer(time.Duration(seconds) * time.Second)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
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
		go d.scanACME(ctx)
		_, _ = fmt.Fprintln(conn, "scan scheduled")
	case "deploy-outdated":
		go d.reconcile(ctx)
		_, _ = fmt.Fprintln(conn, "deploy outdated scheduled")
	case "verify-files":
		deps, err := pfsense.LoadAllDeployments(d.cfg.ConfigPath)
		if err != nil {
			_, _ = fmt.Fprintf(conn, "error: %v\n", err)
			return
		}
		for _, dep := range deps {
			if dep.Enabled && dep.VerifyFiles {
				go d.runVerification(ctx, dep.ID)
			}
		}
		_, _ = fmt.Fprintln(conn, "file verification scheduled")
	case "deploy":
		if len(req.Args) < 2 {
			_, _ = fmt.Fprintln(conn, "error: deploy requires a deployment id")
			return
		}
		go d.runDeployment(ctx, req.Args[1])
		_, _ = fmt.Fprintln(conn, "deploy scheduled")
	default:
		_, _ = fmt.Fprintf(conn, "unknown command: %s\n", req.Args[0])
	}
}

func (d *Daemon) scanLoop(ctx context.Context) {
	ticker := time.NewTicker(time.Duration(d.cfg.ScanInterval) * time.Second)
	defer ticker.Stop()

	// Run once shortly after startup, then on ticker.
	startup := time.NewTimer(5 * time.Second)
	defer startup.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			d.scanACME(ctx)
		case <-startup.C:
			d.scanACME(ctx)
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
			d.reconcile(ctx)
		}
	}
}

// scanACME discovers ACME certs, compares fingerprints, and queues auto-deploy runs.
func (d *Daemon) scanACME(ctx context.Context) {
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
		d.logger.Debug("auto-deploying outdated deployment", slog.String("deployment", id))
		go d.runDeployment(ctx, id)
	}
}

// reconcile pushes renewed local certificates and checks other remote files.
// A failed file check alone never triggers a push.
func (d *Daemon) reconcile(ctx context.Context) {
	d.logger.Debug("running reconciliation")
	if err := d.state.UpdateLastReconcileTime(time.Now().Unix()); err != nil {
		d.logger.Error("failed to update last reconcile time", slog.Any("error", err))
	}

	ids, err := d.outdatedDeploymentIDs(true)
	if err != nil {
		d.logger.Error("reconciliation failed", slog.Any("error", err))
		return
	}

	queued := make(map[string]bool, len(ids))
	for _, id := range ids {
		queued[id] = true
		d.logger.Debug("reconciling outdated deployment", slog.String("deployment", id))
		go d.runDeployment(ctx, id)
	}
	deps, err := pfsense.LoadAllDeployments(d.cfg.ConfigPath)
	if err != nil {
		d.logger.Error("failed to load verification targets", slog.Any("error", err))
		return
	}
	for _, dep := range deps {
		if dep.Enabled && !queued[dep.ID] {
			go d.runVerification(ctx, dep.ID)
		}
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
