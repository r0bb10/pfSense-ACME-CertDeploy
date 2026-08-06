package state

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
)

// State holds the last deployed fingerprint per deployment.
type State struct {
	Deployments map[string]DeploymentState `json:"deployments"`
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

func (m *Manager) Save(state *State) error {
	m.mu.Lock()
	defer m.mu.Unlock()

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

func (m *Manager) UpdateDeployment(id string, ds DeploymentState) error {
	state, err := m.Load()
	if err != nil {
		return err
	}
	state.Deployments[id] = ds
	return m.Save(state)
}
