package daemon

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"sync"
)

// logHandler is a compact, human-readable slog.Handler. It formats records as:
//
//	2026-08-07 16:12:33  INFO  certdeployd starting version=0.1.2 component=daemon
//
// Severity and msg are emitted bare; level is right-padded so the message column
// stays aligned across levels. Time/level/msg are written once by the handler, so
// the default slog `time`/`level`/`msg` keys are never repeated.
type logHandler struct {
	mu    *sync.Mutex
	w     io.Writer
	level slog.Level
	attrs []slog.Attr
}

// NewLogHandler returns a slog.Handler that renders records on one readable
// line.
func NewLogHandler(w io.Writer, level slog.Level) slog.Handler {
	return &logHandler{mu: &sync.Mutex{}, w: w, level: level}
}

func (h *logHandler) Enabled(_ context.Context, l slog.Level) bool {
	return l >= h.level
}

func (h *logHandler) Handle(_ context.Context, r slog.Record) error {
	var b strings.Builder
	b.WriteString(r.Time.Format("2006-01-02 15:04:05"))
	b.WriteString("  ")
	_, _ = fmt.Fprintf(&b, "%-5s", strings.ToUpper(r.Level.String()))
	b.WriteString("  ")
	b.WriteString(r.Message)

	writeAttr := func(a slog.Attr) {
		if a.Equal(slog.Attr{}) {
			return
		}
		b.WriteString(" ")
		b.WriteString(a.Key)
		b.WriteString("=")
		b.WriteString(valueString(a.Value))
	}

	for _, a := range h.attrs {
		writeAttr(a)
	}
	r.Attrs(func(a slog.Attr) bool {
		writeAttr(a)
		return true
	})
	b.WriteString("\n")

	h.mu.Lock()
	defer h.mu.Unlock()
	_, err := io.WriteString(h.w, b.String())
	return err
}

func valueString(v slog.Value) string {
	switch v.Kind() {
	case slog.KindString:
		return v.String()
	case slog.KindDuration:
		return v.Duration().String()
	case slog.KindTime:
		return v.Time().Format("2006-01-02 15:04:05")
	case slog.KindGroup:
		var pairs []string
		for _, g := range v.Group() {
			pairs = append(pairs, g.Key+"="+valueString(g.Value))
		}
		return strings.Join(pairs, " ")
	default:
		return fmt.Sprintf("%v", v.Any())
	}
}

func (h *logHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	return &logHandler{
		mu:    h.mu,
		w:     h.w,
		level: h.level,
		attrs: append(append([]slog.Attr{}, h.attrs...), attrs...),
	}
}

func (h *logHandler) WithGroup(_ string) slog.Handler {
	return h
}