package daemon

import (
	"bytes"
	"context"
	"log/slog"
	"testing"
)

func TestLogHandler(t *testing.T) {
	var buf bytes.Buffer
	h := NewLogHandler(&buf, slog.LevelDebug)
	l := slog.New(h)
	ctx := context.Background()

	l.Log(ctx, slog.LevelInfo, "certdeployd starting", slog.String("version", "2026.8.1"), slog.String("component", "daemon"))
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

	// The level column must be right-padded so the message column is aligned,
	// regardless of level name length. Layout: time(19) + 2 spaces + level +
	// right-pad to 5 + 2 spaces + message.
	const levelStart = 19 + 2
	const levelWidth = 5
	for _, line := range bytes.Split(buf.Bytes(), []byte{'\n'}) {
		if len(line) == 0 {
			continue
		}
		if len(line) < levelStart+levelWidth+2 {
			t.Fatalf("unexpected short line %q", line)
		}
		// The level occupies the full 5-wide field (right-padded with spaces).
		padded := string(line[levelStart : levelStart+levelWidth])
		switch padded {
		case "INFO ", "DEBUG", "ERROR", "WARN ":
		default:
			t.Fatalf("unexpected level field %q in line %q", padded, line)
		}
		// The two hardest crumbs: `  ` (2 spaces) must follow the padded level.
		msgSep := levelStart + levelWidth
		if line[msgSep] != ' ' || line[msgSep+1] != ' ' {
			t.Fatalf("level column not padded to width in line %q", line)
		}
	}
}
