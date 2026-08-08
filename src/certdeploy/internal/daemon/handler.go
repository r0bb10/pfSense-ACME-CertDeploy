package daemon

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"sync"
)

// logHandler renders concise operator-facing records. Debug records retain every
// structured attribute for troubleshooting.
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
	attrs := map[string]slog.Value{}
	for _, attr := range h.attrs {
		attrs[attr.Key] = attr.Value
	}
	r.Attrs(func(attr slog.Attr) bool {
		attrs[attr.Key] = attr.Value
		return true
	})

	var b strings.Builder
	b.WriteString(r.Time.Format("2006-01-02 15:04:05"))
	b.WriteString("  ")
	_, _ = fmt.Fprintf(&b, "%-5s", strings.ToUpper(r.Level.String()))
	b.WriteString("  ")
	b.WriteString(logMessage(r.Message, attrs))

	writeAttr := func(a slog.Attr) {
		if a.Equal(slog.Attr{}) {
			return
		}
		b.WriteString(" ")
		b.WriteString(a.Key)
		b.WriteString("=")
		b.WriteString(valueString(a.Value))
	}

	if r.Level == slog.LevelDebug {
		for _, a := range h.attrs {
			writeAttr(a)
		}
		r.Attrs(func(a slog.Attr) bool {
			writeAttr(a)
			return true
		})
	}
	b.WriteString("\n")

	h.mu.Lock()
	defer h.mu.Unlock()
	_, err := io.WriteString(h.w, b.String())
	return err
}

func logMessage(message string, attrs map[string]slog.Value) string {
	prefix := ""
	if name, ok := attrs["name"]; ok {
		prefix = "[" + name.String()
		if host, ok := attrs["host"]; ok {
			prefix += "@" + host.String()
		}
		prefix += "] "
	} else if component, ok := attrs["component"]; ok && component.String() == "daemon" {
		prefix = "[daemon] "
	}

	switch message {
	case "certdeployd starting":
		if version, ok := attrs["version"]; ok {
			return prefix + "Daemon started (" + version.String() + ")"
		}
		return prefix + "Daemon started"
	case "shutting down":
		return prefix + "Daemon stopped"
	case "starting deployment":
		return prefix + "Deployment started"
	case "deployment completed":
		return prefix + "Deployment completed"
	case "deployment failed; retrying":
		if attempt, ok := attrs["attempt"]; ok {
			return prefix + "Deployment failed; retrying (attempt " + valueString(attempt) + ")"
		}
		return prefix + "Deployment failed; retrying"
	case "host key fingerprint changed":
		return prefix + "Host key accepted"
	default:
		if err, ok := attrs["error"]; ok {
			return prefix + message + ": " + valueString(err)
		}
		return prefix + message
	}
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
