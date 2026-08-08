package ssh

import "testing"

func TestNormalizeHostKeyLine(t *testing.T) {
	const key = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample"

	tests := []struct {
		name string
		port int
		want string
	}{
		{name: "default port", port: 22, want: "example.com " + key},
		{name: "implicit default port", port: 0, want: "example.com " + key},
		{name: "custom port", port: 2222, want: "[example.com]:2222 " + key},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := NormalizeHostKeyLine("[old.example]:2222 "+key, "example.com", tt.port)
			if got != tt.want {
				t.Fatalf("NormalizeHostKeyLine() = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestSelectHostKey(t *testing.T) {
	keys := []HostKeyResult{
		{Algorithm: "ssh-rsa", Fingerprint: "rsa"},
		{Algorithm: "ecdsa-sha2-nistp256", Fingerprint: "ecdsa"},
		{Algorithm: "ssh-ed25519", Fingerprint: "ed25519"},
	}
	if got := selectHostKey(keys); got.Fingerprint != "ed25519" {
		t.Fatalf("selectHostKey() = %q, want ed25519", got.Fingerprint)
	}
}
