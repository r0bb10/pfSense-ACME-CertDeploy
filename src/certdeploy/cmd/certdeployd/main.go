package main

import (
	"flag"
	"log"
	"os"

	"certdeploy/internal/config"
	"certdeploy/internal/daemon"
)

var version = "dev"

func main() {
	configPath := flag.String("config", "/usr/local/etc/certdeploy/config.json", "daemon configuration file")
	showVersion := flag.Bool("version", false, "show version")
	flag.Parse()

	if *showVersion {
		log.Printf("certdeployd %s", version)
		os.Exit(0)
	}

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("failed to load config: %v", err)
	}

	d, err := daemon.New(cfg)
	if err != nil {
		log.Fatalf("failed to create daemon: %v", err)
	}

	if err := d.Run(); err != nil {
		log.Fatalf("daemon error: %v", err)
	}
}
