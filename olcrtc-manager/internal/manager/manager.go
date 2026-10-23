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
	"strings"
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
	// Recreate forces a brand new room and crypto key, i.e. a fresh olcrtc://
	// key for the "recreate" button. Without it an unchanged config keeps the
	// running srv untouched (see Create).
	Recreate bool `json:"recreate,omitempty"`
}

// Manager wraps instance store + process supervision.
type Manager struct {
	cfg *config.Config
	s   *store.Store

	mu       sync.Mutex
	cmds     map[string]*exec.Cmd // id -> running process
	dead     map[string]bool      // id -> process exited and needs a restart
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
		dead: make(map[string]bool),
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
	// Restart only when something that ends up in the YAML actually changed.
	// The reconcile cron and every dashboard key fetch hit this endpoint, and
	// bouncing the srv each time dropped the tunnel the user had just brought up.
	restart := true
	if existing != nil {
		inst = existing
		restart = existing.Provider != provider ||
			existing.Transport != transport ||
			existing.Token != token ||
			existing.DNS != dns ||
			existing.ExpiresAt != req.ExpiresAt ||
			existing.CryptoKey == "" ||
			existing.RoomID == ""
		inst.Provider = provider
		inst.Transport = transport
		inst.Token = token
		inst.DNS = dns
		inst.ExpiresAt = req.ExpiresAt
		if req.Comment != "" {
			inst.Comment = req.Comment
		}
		if req.Recreate {
			inst.RoomID = m.resolveRoom(provider, "", "")
			k, err := randomHex(32)
			if err != nil {
				return nil, fmt.Errorf("generate key: %w", err)
			}
			inst.CryptoKey = k
			restart = true
		}
		// migration: older instances stored a bare UUID room for jitsi, which is
		// not a valid room URL — normalise it so the olcrtc:// URI works.
		roomBefore := inst.RoomID
		inst.RoomID = m.resolveRoom(provider, inst.RoomID, inst.ID)
		if inst.RoomID != roomBefore {
			restart = true
		}
		inst.Status = "active"
	} else {
		roomID := m.resolveRoom(provider, req.RoomID, "")
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

	// Nothing in the config changed and the srv is still running: keep the
	// tunnel as is. If the process died (or was never started) fall through and
	// bring it up again, so this still acts as a self-healing path.
	if !restart {
		if cmd, ok := m.cmds[inst.ID]; ok && cmd != nil && cmd.Process != nil && !m.dead[inst.ID] {
			if err := m.s.Update(inst); err != nil {
				return nil, fmt.Errorf("db update: %w", err)
			}
			return inst, nil
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
	delete(m.dead, id)
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

// Restore starts every active, non-expired instance found in the store.
//
// Called once at startup: processes do not survive a container restart, and
// without this the olcrtc:// keys issued earlier would stop working until each
// user reopened the cabinet.
func (m *Manager) Restore() {
	m.mu.Lock()
	defer m.mu.Unlock()
	now := time.Now().Unix()
	list, err := m.s.List()
	if err != nil {
		log.Printf("restore: list failed: %v", err)
		return
	}
	started := 0
	for _, i := range list {
		if i.Status != "active" || (i.ExpiresAt > 0 && i.ExpiresAt <= now) {
			continue
		}
		if err := m.startProcess(i); err != nil {
			log.Printf("restore: start instance %s failed: %v", i.ID, err)
			continue
		}
		started++
	}
	if started > 0 {
		log.Printf("restored %d running olcrtc instance(s)", started)
	}
}

func (m *Manager) reap() {
	m.mu.Lock()
	defer m.mu.Unlock()
	for id := range m.cmds {
		if !m.dead[id] {
			continue
		}
		delete(m.dead, id)
		log.Printf("instance %s died, restarting", id)
		i, err := m.s.GetByID(id)
		if err != nil || i == nil || i.Status != "active" {
			delete(m.cmds, id)
			continue
		}
		if err := m.startProcess(i); err != nil {
			log.Printf("restart instance %s failed: %v", id, err)
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
	// The olcrtc CLI takes exactly one positional argument — the path to the
	// YAML config (e.g. `olcrtc /etc/olcrtc/server.yaml`). There are no CLI
	// flags; passing "-c" makes runWithArgs() reject it with
	// ErrConfigPathRequired ("usage: olcrtc <config.yaml>") and the srv process
	// exits immediately, which is why issued olcrtc:// keys never worked.
	cmd := exec.Command(m.cfg.OlcRTCBin, cfgPath)
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
	delete(m.dead, i.ID)
	// Wait exactly once per process: a second Process.Wait() call returns
	// immediately with ECHILD, which used to make reap() think the instance
	// had died and restart it every tick.
	go func(c *exec.Cmd, id string) {
		_, _ = c.Process.Wait()
		if logFile != nil {
			_ = logFile.Close()
		}
		m.mu.Lock()
		m.dead[id] = true
		m.mu.Unlock()
	}(cmd, i.ID)
	return nil
}

// Migrate normalizes stored room ids and rewrites their YAML configs.
//
// Older builds stored a bare UUID as room.id for the "jitsi" provider, which is
// not a valid Jitsi room URL and produced broken olcrtc:// URIs. Call once at
// startup; it never starts processes.
func (m *Manager) Migrate() {
	m.mu.Lock()
	defer m.mu.Unlock()

	list, err := m.s.List()
	if err != nil {
		log.Printf("migrate: list failed: %v", err)
		return
	}
	for _, i := range list {
		room := m.resolveRoom(i.Provider, i.RoomID, i.ID)
		if room == i.RoomID {
			continue
		}
		log.Printf("migrate: user %d room %q -> %q", i.UserID, i.RoomID, room)
		i.RoomID = room
		if err := m.s.Update(i); err != nil {
			log.Printf("migrate: db update user %d failed: %v", i.UserID, err)
			continue
		}
		if err := m.writeConfig(i); err != nil {
			log.Printf("migrate: write config user %d failed: %v", i.UserID, err)
		}
	}
}

// resolveRoom returns a provider-appropriate room id.
//
// The olcrtc client convention (docs/uri.md) requires room.id for the "jitsi"
// provider to be a full room URL of the form "https://host/room". Anything that
// is not already a URL (a bare UUID produced by older builds, or an empty
// value) is converted to "<JitsiServer>/olcrtc-<slug>". Other providers keep
// the caller-supplied id (a random UUID when none is given).
func (m *Manager) resolveRoom(provider, roomID, seed string) string {
	if !strings.EqualFold(provider, "jitsi") {
		if roomID == "" {
			return uuid.NewString()
		}
		return roomID
	}
	if strings.HasPrefix(roomID, "http://") || strings.HasPrefix(roomID, "https://") {
		return roomID
	}
	base := m.cfg.JitsiServer
	if base == "" {
		base = "https://meet.jit.si"
	}
	slug := roomSlug(roomID)
	if slug == "" {
		slug = roomSlug(seed)
	}
	if slug == "" {
		slug = randomSlug(8)
	}
	return strings.TrimRight(base, "/") + "/olcrtc-" + slug
}

// roomSlug reduces an arbitrary string to 8 lowercase alphanumeric chars,
// suitable for a Jitsi room name.
func roomSlug(s string) string {
	var b strings.Builder
	for _, r := range strings.ToLower(s) {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
			if b.Len() == 8 {
				break
			}
		}
	}
	return b.String()
}

// randomSlug returns n random lowercase alphanumeric characters.
func randomSlug(n int) string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789"
	buf := make([]byte, n)
	if _, err := rand.Read(buf); err != nil {
		return strings.Repeat("x", n)
	}
	for i := range buf {
		buf[i] = alphabet[int(buf[i])%len(alphabet)]
	}
	return string(buf)
}

// BuildUserURI returns the olcrtc:// URI for a client app.
//
// Format (uri spec v1): olcrtc://<Provider>?<Transport>@<RoomID>#<EncryptionKey>$<MIMO>
// where <MIMO> is a free-form client label. It is kept so the issued key is
// byte-for-byte the same shape as the reference key from the vendor guide,
// e.g. olcrtc://jitsi?datachannel@https://meet.egovm.ru/olcrtc-uarqkcdq#<64hex>$olc
func BuildUserURI(i *store.Instance, mimo string) string {
	if mimo == "" {
		mimo = i.Comment
	}
	return fmt.Sprintf("olcrtc://%s?%s@%s#%s$%s",
		i.Provider, i.Transport, i.RoomID, i.CryptoKey, sanitizeMIMO(mimo))
}

// sanitizeMIMO keeps the free-form client label on a single line and free of
// the URI separators ('?', '@', '#', '$', '<', '>'), because a stray separator
// inside the label makes the olcrtc:// URI unparseable by the client. Falls
// back to "olc" (the label used by the vendor guide).
func sanitizeMIMO(s string) string {
	s = strings.ReplaceAll(strings.ReplaceAll(s, "\r", " "), "\n", " ")
	s = strings.TrimSpace(s)
	if s == "" {
		return "olc"
	}
	var b strings.Builder
	for _, r := range s {
		switch r {
		case '?', '@', '#', '$', '<', '>':
			b.WriteRune('-')
		default:
			b.WriteRune(r)
		}
	}
	out := strings.TrimSpace(b.String())
	if out == "" {
		return "olc"
	}
	if r := []rune(out); len(r) > 64 {
		out = strings.TrimSpace(string(r[:64]))
	}
	return out
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
