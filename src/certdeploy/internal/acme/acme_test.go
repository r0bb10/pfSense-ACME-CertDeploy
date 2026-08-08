package acme

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestValidateKeyPair(t *testing.T) {
	certKey := mustRSAKey(t)
	otherKey := mustRSAKey(t)
	dir := t.TempDir()
	certPath := filepath.Join(dir, "cert.pem")
	keyPath := filepath.Join(dir, "key.pem")
	if err := os.WriteFile(certPath, certificatePEM(t, certKey), 0o600); err != nil {
		t.Fatal(err)
	}

	tests := []struct {
		name string
		key  *rsa.PrivateKey
		want bool
	}{
		{name: "matching key", key: certKey},
		{name: "different key", key: otherKey, want: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			der := x509.MarshalPKCS1PrivateKey(tt.key)
			if err := os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: der}), 0o600); err != nil {
				t.Fatal(err)
			}
			err := ValidateKeyPair(certPath, keyPath)
			if (err != nil) != tt.want {
				t.Fatalf("ValidateKeyPair() error = %v, want error %t", err, tt.want)
			}
		})
	}
}

func mustRSAKey(t *testing.T) *rsa.PrivateKey {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	return key
}

func certificatePEM(t *testing.T, key *rsa.PrivateKey) []byte {
	t.Helper()
	der, err := x509.CreateCertificate(rand.Reader, &x509.Certificate{
		SerialNumber: big.NewInt(1),
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
	}, &x509.Certificate{}, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
}
