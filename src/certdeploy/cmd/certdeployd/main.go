package main

import (
	"flag"
	"fmt"
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
		fmt.Printf("%s\n", version)
		os.Exit(0)
	}

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("failed to load config: %v", err)
	}

	d, err := daemon.New(cfg, version)
	if err != nil {
		log.Fatalf("failed to create daemon: %v", err)
	}

	if err := d.Run(); err != nil {
		log.Fatalf("daemon error: %v", err)
	}
}
