// Package manager manages running olcrtc srv processes (start, stop, watch).
package manager

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"syscall"
	"time"

	"github.com/google/uuid"
	"gopkg.in/yaml.v3"

	"olcrtc-manager/internal/config"
	"olcrtc-manager/internal/store"
)

// InstanceRequest is the input payload for creating / updating an instance.
type InstanceRequest struct {
	UserID    int64  `json:"user_id"`
	Provider  string `json:"provider"`
	Transport string `json:"transport"`
	Token     string `json:"token,omitempty"`
	DNS       string `json:"dns"`
	ExpiresAt int64  `json:"expires_at"`
	Comment   string `json:"comment"`
	RoomID    string `json:"room_id,omitempty"`
	CryptoKey string `json:"crypto_key,omitempty"`
}

// Manager wraps instance store + process supervision.
type Manager struct {
	cfg *config.Config
	s   *store.Store

	mu       sync.Mutex
	cmds     map[string]*exec.Cmd // id -> running process
}

// New creates a new Manager and ensures the instance directory exists.
func New(cfg *config.Config, s *store.Store) (*Manager, error) {
	if err := os.MkdirAll(cfg.InstancesDir, 0o750); err != nil {
		return nil, fmt.Errorf("mkdir instances dir: %w", err)
	}
	return &Manager{
		cfg:  cfg,
		s:    s,
		cmds: make(map[string]*exec.Cmd),
	}, nil
}

// Create creates (or reactivates) an olcrtc srv instance for a user.
func (m *Manager) Create(req InstanceRequest) (*store.Instance, error) {
	if req.UserID <= 0 {
		return nil, errors.New("user_id is required")
	}
	provider := req.Provider
	if provider == "" {
		provider = m.cfg.DefaultProvider
	}
	transport := req.Transport
	if transport == "" {
		transport = m.cfg.DefaultTransport
	}
	dns := req.DNS
	if dns == "" {
		dns = m.cfg.DefaultDNS
	}
	token := req.Token
	if token == "" {
		token = m.cfg.DefaultToken
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	existing, err := m.s.GetByUser(req.UserID)
	if err != nil {
		return nil, err
	}

	var inst *store.Instance
	if existing != nil {
		inst = existing
		inst.Provider = provider
		inst.Transport = transport
		inst.Token = token
		inst.DNS = dns
		inst.ExpiresAt = req.ExpiresAt
		if req.Comment != "" {
			inst.Comment = req.Comment
		}
		inst.Status = "active"
	} else {
		roomID := req.RoomID
		if roomID == "" {
			roomID = uuid.NewString()
		}
		cryptoKey := req.CryptoKey
		if cryptoKey == "" {
			k, err := randomHex(32)
			if err != nil {
				return nil, fmt.Errorf("generate key: %w", err)
			}
			cryptoKey = k
		}
		inst = &store.Instance{
			ID:        uuid.NewString(),
			UserID:    req.UserID,
			RoomID:    roomID,
			CryptoKey: cryptoKey,
			Provider:  provider,
			Transport: transport,
			Token:     token,
			DNS:       dns,
			ExpiresAt: req.ExpiresAt,
			Status:    "active",
			Comment:   req.Comment,
		}
		if err := m.s.Create(inst); err != nil {
			return nil, fmt.Errorf("db create: %w", err)
		}
	}

	if err := m.s.Update(inst); err != nil {
		return nil, fmt.Errorf("db update: %w", err)
	}

	if err := m.writeConfig(inst); err != nil {
		return nil, fmt.Errorf("write config: %w", err)
	}

	// Stop any previous instance for this user
	if old, ok := m.cmds[inst.ID]; ok && old != nil && old.Process != nil {
		_ = old.Process.Signal(syscall.SIGTERM)
		delete(m.cmds, inst.ID)
	}

	if err := m.startProcess(inst); err != nil {
		log.Printf("start process failed (user %d): %v (instance saved, will retry on Watch)", inst.UserID, err)
	}

	return inst, nil
}

// Stop stops and removes an instance by id.
func (m *Manager) Stop(id string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	i, err := m.s.GetByID(id)
	if err != nil {
		return err
	}
	if cmd, ok := m.cmds[id]; ok && cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Signal(syscall.SIGTERM)
		wait := make(chan struct{})
		go func() {
			_, _ = cmd.Process.Wait()
			close(wait)
		}()
		select {
		case <-wait:
		case <-time.After(5 * time.Second):
			_ = cmd.Process.Kill()
		}
		delete(m.cmds, id)
	}
	if i != nil {
		i.Status = "stopped"
		i.PID = 0
		_ = m.s.Update(i)
		_ = os.Remove(m.configPath(i))
	}
	return nil
}

// StopByUser stops the instance associated with a user id.
func (m *Manager) StopByUser(userID int64) error {
	i, err := m.s.GetByUser(userID)
	if err != nil {
		return err
	}
	if i == nil {
		return nil
	}
	return m.Stop(i.ID)
}

// Get returns an instance by id.
func (m *Manager) Get(id string) (*store.Instance, error) {
	return m.s.GetByID(id)
}

// GetByUser returns an instance by user id.
func (m *Manager) GetByUser(userID int64) (*store.Instance, error) {
	return m.s.GetByUser(userID)
}

// List returns all stored instances.
func (m *Manager) List() ([]*store.Instance, error) {
	return m.s.List()
}

// StopAll terminates every running process. Called on shutdown.
func (m *Manager) StopAll() {
	m.mu.Lock()
	defer m.mu.Unlock()
	for id, cmd := range m.cmds {
		if cmd == nil || cmd.Process == nil {
			continue
		}
		log.Printf("stopping instance %s", id)
		_ = cmd.Process.Signal(syscall.SIGTERM)
	}
}

// Watch is a blocking loop that reaps dead processes and expires instances.
func (m *Manager) Watch() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	for range ticker.C {
		m.reap()
		m.expire()
	}
}

func (m *Manager) reap() {
	m.mu.Lock()
	defer m.mu.Unlock()
	for id, cmd := range m.cmds {
		if cmd == nil || cmd.Process == nil {
			continue
		}
		done := make(chan struct{})
		go func(c *exec.Cmd) {
			_, _ = c.Process.Wait()
			close(done)
		}(cmd)
		select {
		case <-done:
			log.Printf("instance %s died, restarting", id)
			i, err := m.s.GetByID(id)
			if err != nil || i == nil || i.Status != "active" {
				delete(m.cmds, id)
				continue
			}
			_ = m.startProcess(i)
		default:
		}
	}
}

func (m *Manager) expire() {
	m.mu.Lock()
	defer m.mu.Unlock()
	now := time.Now().Unix()
	list, err := m.s.ListExpiredActive(now)
	if err != nil {
		log.Printf("expire query failed: %v", err)
		return
	}
	for _, i := range list {
		if cmd, ok := m.cmds[i.ID]; ok && cmd != nil && cmd.Process != nil {
			_ = cmd.Process.Signal(syscall.SIGTERM)
			delete(m.cmds, i.ID)
		}
		i.Status = "expired"
		i.PID = 0
		_ = m.s.Update(i)
		_ = os.Remove(m.configPath(i))
		log.Printf("instance %s (user %d) expired", i.ID, i.UserID)
	}
}

func (m *Manager) configPath(i *store.Instance) string {
	return filepath.Join(m.cfg.InstancesDir, i.ID+".yaml")
}

// ServerYAML is the olcrtc srv mode yaml config.
type ServerYAML struct {
	Mode   string `yaml:"mode"`
	Debug  bool   `yaml:"debug,omitempty"`
	Auth   Auth   `yaml:"auth"`
	Room   Room   `yaml:"room"`
	Crypto Crypto `yaml:"crypto"`
	Net    Net    `yaml:"net"`
}
type Auth struct {
	Provider string `yaml:"provider"`
	Token    string `yaml:"token,omitempty"`
}
type Room struct {
	ID string `yaml:"id"`
}
type Crypto struct {
	Key string `yaml:"key"`
}
type Net struct {
	Transport string `yaml:"transport"`
	DNS       string `yaml:"dns"`
}

func (m *Manager) writeConfig(i *store.Instance) error {
	cfg := ServerYAML{
		Mode: "srv",
		Auth: Auth{Provider: i.Provider, Token: i.Token},
		Room: Room{ID: i.RoomID},
		Crypto: Crypto{Key: i.CryptoKey},
		Net:  Net{Transport: i.Transport, DNS: i.DNS},
	}
	b, err := yaml.Marshal(cfg)
	if err != nil {
		return err
	}
	return os.WriteFile(m.configPath(i), b, 0o640)
}

func (m *Manager) startProcess(i *store.Instance) error {
	cfgPath := m.configPath(i)
	if _, err := os.Stat(cfgPath); err != nil {
		return fmt.Errorf("config missing: %w", err)
	}
	cmd := exec.Command(m.cfg.OlcRTCBin, "-c", cfgPath)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	logFile, err := os.OpenFile(filepath.Join(m.cfg.InstancesDir, i.ID+".log"), os.O_WRONLY|os.O_APPEND|os.O_CREATE, 0o640)
	if err == nil {
		cmd.Stdout = logFile
		cmd.Stderr = logFile
	}
	if err := cmd.Start(); err != nil {
		if logFile != nil {
			_ = logFile.Close()
		}
		return err
	}
	i.PID = cmd.Process.Pid
	if err := m.s.Update(i); err != nil {
		log.Printf("db update pid failed: %v", err)
	}
	m.cmds[i.ID] = cmd
	go func(c *exec.Cmd, id string) {
		_, _ = c.Process.Wait()
		if logFile != nil {
			_ = logFile.Close()
		}
	}(cmd, i.ID)
	return nil
}

// BuildUserURI returns the olcrtc:// URI for a client app.
func BuildUserURI(i *store.Instance, mimo string) string {
	if mimo == "" {
		mimo = i.Comment
	}
	return fmt.Sprintf("olcrtc://%s?%s@%s#%s$%s",
		i.Provider, i.Transport, i.RoomID, i.CryptoKey, mimo)
}

// BuildUserYAML returns the client yaml config text.
func BuildUserYAML(i *store.Instance) string {
	cfg := struct {
		Mode   string `yaml:"mode"`
		Debug  bool   `yaml:"debug,omitempty"`
		Auth   Auth   `yaml:"auth"`
		Room   Room   `yaml:"room"`
		Crypto Crypto `yaml:"crypto"`
		Net    struct {
			Transport string `yaml:"transport"`
			DNS       string `yaml:"dns"`
		} `yaml:"net"`
		Socks struct {
			Host string `yaml:"host"`
			Port int    `yaml:"port"`
		} `yaml:"socks"`
	}{
		Mode: "cnc",
		Auth: Auth{Provider: i.Provider, Token: i.Token},
		Room: Room{ID: i.RoomID},
		Crypto: Crypto{Key: i.CryptoKey},
	}
	cfg.Net.Transport = i.Transport
	cfg.Net.DNS = i.DNS
	cfg.Socks.Host = "127.0.0.1"
	cfg.Socks.Port = 8808
	b, _ := yaml.Marshal(cfg)
	return string(b)
}

func randomHex(n int) (string, error) {
	buf := make([]byte, n)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}
