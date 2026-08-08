package daemon

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLogFileReopen(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "certdeploy.log")

	lf, err := openLogFile(path, 0640)
	if err != nil {
		t.Fatalf("open failed: %v", err)
	}
	if _, err := lf.Write([]byte("line1\n")); err != nil {
		t.Fatalf("write1: %v", err)
	}

	// Simulate newsyslog rotation: rename current file, then reopen.
	if err := os.Rename(path, path+".0"); err != nil {
		t.Fatalf("rename: %v", err)
	}
	if err := lf.Reopen(); err != nil {
		t.Fatalf("reopen: %v", err)
	}
	if _, err := lf.Write([]byte("line2\n")); err != nil {
		t.Fatalf("write2: %v", err)
	}
	if err := lf.Close(); err != nil {
		t.Fatalf("close: %v", err)
	}

	cur, _ := os.ReadFile(path)
	old, _ := os.ReadFile(path + ".0")
	if string(cur) != "line2\n" {
		t.Errorf("current file = %q, want %q", cur, "line2\n")
	}
	if string(old) != "line1\n" {
		t.Errorf("rotated file = %q, want %q", old, "line1\n")
	}
}
