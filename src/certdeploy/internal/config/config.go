package config

import (
	"encoding/json"
	"os"
)

// Config is the runtime configuration written by the PHP package.
type Config struct {
	Enable                bool   `json:"enable"`
	ACMEMonitor           bool   `json:"acme_monitor"`
	ScanInterval          int    `json:"scan_interval"`
	Reconcile             bool   `json:"reconcile"`
	ReconcileInterval     int    `json:"reconcile_interval"`
	MaxConcurrentTargets  int    `json:"max_concurrent_targets"`
	DefaultSSHTimeout     int    `json:"default_ssh_timeout"`
	DefaultCommandTimeout int    `json:"default_cmd_timeout"`
	DefaultRetryCount     int    `json:"default_retry_count"`
	DefaultRetryDelay     int    `json:"default_retry_delay"`
	LogLevel              string `json:"log_level"`
	ConfigPath            string `json:"config_path"`
	StateFile             string `json:"state_file"`
	LogFile               string `json:"log_file"`
	Socket                string `json:"socket"`
}

func Load(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, err
	}
	if cfg.MaxConcurrentTargets < 1 {
		cfg.MaxConcurrentTargets = 1
	}
	if cfg.DefaultSSHTimeout < 1 {
		cfg.DefaultSSHTimeout = 30
	}
	if cfg.DefaultCommandTimeout < 1 {
		cfg.DefaultCommandTimeout = 60
	}
	if cfg.ScanInterval < 1 {
		cfg.ScanInterval = 60
	}
	if cfg.ReconcileInterval < 1 {
		cfg.ReconcileInterval = 3600
	}
	return &cfg, nil
}
