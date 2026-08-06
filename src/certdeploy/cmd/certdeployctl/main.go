package main

import (
	"flag"
	"fmt"
	"log"
	"os"

	"certdeploy/internal/control"
)

var version = "dev"

func main() {
	socket := flag.String("socket", "/var/run/certdeploy/certdeploy.sock", "daemon control socket")
	showVersion := flag.Bool("version", false, "show version")
	flag.Parse()

	if *showVersion {
		fmt.Printf("certdeployctl %s\n", version)
		os.Exit(0)
	}

	args := flag.Args()
	if len(args) == 0 {
		fmt.Fprintf(os.Stderr, "usage: certdeployctl <command> [args]\n")
		os.Exit(2)
	}

	client := control.New(*socket)
	if err := client.Exec(args...); err != nil {
		log.Fatalf("certdeployctl: %v", err)
	}
}
