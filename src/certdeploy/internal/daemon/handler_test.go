package daemon

import (
	"bytes"
	"context"
	"log/slog"
	"testing"
	"time"
)

func TestLogHandler(t *testing.T) {
	var buf bytes.Buffer
	h := NewLogHandler(&buf, slog.LevelDebug)
	l := slog.New(h)
	ctx := context.Background()

	l.Log(ctx, slog.LevelInfo, "certdeployd starting", slog.String("version", "0.1.2"), slog.String("component", "daemon"))
	l.Log(ctx, slog.LevelDebug, "retry", slog.Int("attempt", 3))
	l.Log(ctx, slog.LevelError, "failed to connect", slog.String("target", "n100"), slog.String("error", "exit status 1"))

	for _, line := range bytes.Split(buf.Bytes(), []byte{'\n'}) {
		if len(line) > 0 {
			t.Logf("%s", line)
		}
	}

	if n := buf.Len(); n == 0 {
		t.Fatal("no output written")
	}

	// ensure time formatting works
	now := time.Now()
	if now.Format("2006") == "" {
		t.Fatal("bad time")
	}
}
