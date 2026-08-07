package daemon

import (
	"os"
	"path/filepath"
	"sync"
)

// logFile is an append-only log writer that can swap its underlying file
// on demand (used to reopen the log after newsyslog(8) rotates it). All
// writes and reopens are serialized through an internal mutex.
type logFile struct {
	mu   sync.Mutex
	path string
	mode os.FileMode
	f    *os.File
}

// newLogFile opens (creating if necessary) the log file at path in append
// mode. The file mode is applied when the file is first created.
func openLogFile(path string, mode os.FileMode) (*logFile, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0750); err != nil {
		return nil, err
	}
	l := &logFile{path: path, mode: mode}
	if err := l.reopen(); err != nil {
		return nil, err
	}
	return l, nil
}

func (l *logFile) reopen() error {
	f, err := os.OpenFile(l.path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, l.mode)
	if err != nil {
		return err
	}
	l.f = f
	return nil
}

// Write appends p to the current underlying file.
func (l *logFile) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.f == nil {
		return 0, os.ErrClosed
	}
	return l.f.Write(p)
}

// Reopen closes the current file (if any) and opens a fresh handle. It is
// called after newsyslog(8) rotates the log file so subsequent writes target
// the newly created file.
func (l *logFile) Reopen() error {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.f != nil {
		_ = l.f.Close()
		l.f = nil
	}
	return l.reopen()
}

// Close closes the current underlying file.
func (l *logFile) Close() error {
	l.mu.Lock()
	defer l.mu.Unlock()
	var err error
	if l.f != nil {
		err = l.f.Close()
		l.f = nil
	}
	return err
}