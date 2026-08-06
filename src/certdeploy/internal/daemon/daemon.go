package daemon

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"certdeploy/internal/config"
	"certdeploy/internal/deploy"
	"certdeploy/internal/ssh"
	"certdeploy/internal/state"
)

// Daemon runs the CertDeploy background service.
type Daemon struct {
	cfg     *config.Config
	logger  *slog.Logger
	logFile *os.File
	state   *state.Manager
	ssh     *ssh.Runner
	engine  *deploy.Engine
	socket  string
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

func newLogger(path, level string) (*slog.Logger, *os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0750); err != nil {
		return nil, nil, err
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0640)
	if err != nil {
		return nil, nil, err
	}
	handler := slog.NewTextHandler(f, &slog.HandlerOptions{Level: parseLogLevel(level)})
	return slog.New(handler), f, nil
}

func New(cfg *config.Config) (*Daemon, error) {
	lg, lf, err := newLogger(cfg.LogFile, cfg.LogLevel)
	if err != nil {
		return nil, fmt.Errorf("failed to create logger: %w", err)
	}

	stateMgr := state.NewManager(cfg.StateFile)
	runner := ssh.NewRunner(cfg, lg)
	engine := deploy.NewEngine(cfg, lg, stateMgr, runner)

	d := &Daemon{
		cfg:     cfg,
		logger:  lg,
		logFile: lf,
		state:   stateMgr,
		ssh:     runner,
		engine:  engine,
		socket:  cfg.Socket,
	}

	if err := os.MkdirAll("/var/run/certdeploy", 0750); err != nil {
		return nil, err
	}
	return d, nil
}

func (d *Daemon) Run() error {
	if !d.cfg.Enable {
		log.Println("certdeploy is disabled in configuration")
		return nil
	}

	d.logger.Info("certdeployd starting", slog.String("component", "daemon"), slog.String("version", "dev"))

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Start control socket listener.
	ln, err := net.Listen("unix", d.socket)
	if err != nil {
		return fmt.Errorf("failed to listen on %s: %w", d.socket, err)
	}
	defer func() {
		if err := ln.Close(); err != nil {
			d.logger.Error("failed to close listener", slog.String("component", "daemon"), slog.Any("error", err))
		}
	}()
	if err := os.Chmod(d.socket, 0600); err != nil {
		d.logger.Error("failed to chmod socket", slog.String("component", "daemon"), slog.Any("error", err))
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
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	<-sigCh

	cancel()
	d.logger.Info("shutting down", slog.String("component", "daemon"))
	return d.logFile.Close()
}

func (d *Daemon) acceptLoop(ctx context.Context, ln net.Listener) {
	for {
		conn, err := ln.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			d.logger.Error("accept error", slog.String("component", "daemon"), slog.Any("error", err))
			continue
		}
		go d.handleConn(ctx, conn)
	}
}

func (d *Daemon) handleConn(ctx context.Context, conn net.Conn) {
	defer func() {
		if err := conn.Close(); err != nil {
			d.logger.Error("failed to close connection", slog.String("component", "daemon"), slog.Any("error", err))
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
		_, _ = fmt.Fprintln(conn, "scan scheduled")
		// TODO: trigger scan.
	case "deploy-outdated":
		_, _ = fmt.Fprintln(conn, "deploy outdated scheduled")
		// TODO: trigger outdated deployment.
	default:
		_, _ = fmt.Fprintf(conn, "unknown command: %s\n", req.Args[0])
	}
}

func (d *Daemon) scanLoop(ctx context.Context) {
	ticker := time.NewTicker(time.Duration(d.cfg.ScanInterval) * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			d.logger.Debug("scanning ACME state", slog.String("component", "daemon"))
			// TODO: scan and queue deployments.
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
			d.logger.Debug("running reconciliation", slog.String("component", "daemon"))
			// TODO: reconcile desired vs deployed state.
		}
	}
}
