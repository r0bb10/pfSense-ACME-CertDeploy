package state

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
)

// State holds global and per-deployment runtime state.
type State struct {
	Deployments       map[string]DeploymentState `json:"deployments"`
	LastScanTime      int64                      `json:"last_scan_time"`
	LastReconcileTime int64                      `json:"last_reconcile_time"`
}

type DeploymentState struct {
	LastDeployedFingerprint string `json:"last_deployed_fingerprint"`
	LastDeployedTime        int64  `json:"last_deployed_time"`
	LastRunStatus           string `json:"last_run_status"`
	HostKeyFingerprint      string `json:"host_key_fingerprint"`
}

// Manager persists runtime state.
type Manager struct {
	mu   sync.Mutex
	path string
}

func NewManager(path string) *Manager {
	return &Manager{path: path}
}

func (m *Manager) Load() (*State, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.load()
}

func (m *Manager) load() (*State, error) {
	state := &State{Deployments: map[string]DeploymentState{}}
	data, err := os.ReadFile(m.path)
	if err != nil {
		if os.IsNotExist(err) {
			return state, nil
		}
		return nil, err
	}
	if err := json.Unmarshal(data, state); err != nil {
		return nil, err
	}
	if state.Deployments == nil {
		state.Deployments = map[string]DeploymentState{}
	}
	return state, nil
}

func (m *Manager) save(state *State) error {
	if err := os.MkdirAll(filepath.Dir(m.path), 0750); err != nil {
		return err
	}
	data, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	tmp := m.path + ".tmp"
	if err := os.WriteFile(tmp, data, 0640); err != nil {
		return err
	}
	return os.Rename(tmp, m.path)
}

func (m *Manager) UpdateDeployment(id string, ds DeploymentState, successful bool) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	state, err := m.load()
	if err != nil {
		return err
	}
	if !successful {
		previous := state.Deployments[id]
		ds.LastDeployedFingerprint = previous.LastDeployedFingerprint
		ds.LastDeployedTime = previous.LastDeployedTime
		if ds.HostKeyFingerprint == "" {
			ds.HostKeyFingerprint = previous.HostKeyFingerprint
		}
	}
	state.Deployments[id] = ds
	return m.save(state)
}

func (m *Manager) UpdateLastScanTime(t int64) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	state, err := m.load()
	if err != nil {
		return err
	}
	state.LastScanTime = t
	return m.save(state)
}

func (m *Manager) UpdateLastReconcileTime(t int64) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	state, err := m.load()
	if err != nil {
		return err
	}
	state.LastReconcileTime = t
	return m.save(state)
}
