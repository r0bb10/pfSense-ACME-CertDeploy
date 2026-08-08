package state

import (
	"path/filepath"
	"testing"
)

func TestUpdateDeploymentPreservesLastSuccessOnFailure(t *testing.T) {
	m := NewManager(filepath.Join(t.TempDir(), "state.json"))
	if err := m.UpdateDeployment("target", DeploymentState{
		LastDeployedFingerprint: "success-fingerprint",
		LastDeployedTime:        100,
		LastRunStatus:           "success",
		HostKeyFingerprint:      "host-key",
	}, true); err != nil {
		t.Fatal(err)
	}
	if err := m.UpdateDeployment("target", DeploymentState{LastRunStatus: "failed"}, false); err != nil {
		t.Fatal(err)
	}

	got, err := m.Load()
	if err != nil {
		t.Fatal(err)
	}
	deployment := got.Deployments["target"]
	if deployment.LastDeployedFingerprint != "success-fingerprint" || deployment.LastDeployedTime != 100 {
		t.Fatalf("failure replaced successful deployment state: %+v", deployment)
	}
	if deployment.LastRunStatus != "failed" {
		t.Fatalf("LastRunStatus = %q, want failed", deployment.LastRunStatus)
	}
	if deployment.HostKeyFingerprint != "host-key" {
		t.Fatalf("HostKeyFingerprint = %q, want host-key", deployment.HostKeyFingerprint)
	}
}
