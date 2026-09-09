// Package store implements SQLite persistence for olcrtc instances.
package store

import (
	"database/sql"
	"errors"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

// Instance is the stored representation of one olcrtc srv instance.
type Instance struct {
	ID        string    `json:"id"`
	UserID    int64     `json:"user_id"`
	RoomID    string    `json:"room_id"`
	CryptoKey string    `json:"crypto_key"`
	Provider  string    `json:"provider"`
	Transport string    `json:"transport"`
	Token     string    `json:"token,omitempty"`
	DNS       string    `json:"dns"`
	ExpiresAt int64     `json:"expires_at"`
	CreatedAt int64     `json:"created_at"`
	UpdatedAt int64     `json:"updated_at"`
	Status    string    `json:"status"` // active, stopped, expired
	Comment   string    `json:"comment"`
	PID       int       `json:"pid,omitempty"`
}

// Store wraps a SQL database.
type Store struct {
	db *sql.DB
}

// New opens (and initialises if needed) the SQLite database.
func New(path string) (*Store, error) {
	db, err := sql.Open("sqlite3", path+"?_pragma=journal_mode(wal)&_pragma=busy_timeout(5000)")
	if err != nil {
		return nil, err
	}
	if err := db.Ping(); err != nil {
		return nil, err
	}
	s := &Store{db: db}
	if err := s.migrate(); err != nil {
		return nil, err
	}
	return s, nil
}

// Close closes the underlying DB connection.
func (s *Store) Close() error {
	return s.db.Close()
}

func (s *Store) migrate() error {
	stmts := []string{
		`CREATE TABLE IF NOT EXISTS instances (
			id TEXT PRIMARY KEY,
			user_id INTEGER NOT NULL DEFAULT 0,
			room_id TEXT NOT NULL,
			crypto_key TEXT NOT NULL,
			provider TEXT NOT NULL,
			transport TEXT NOT NULL,
			token TEXT DEFAULT '',
			dns TEXT NOT NULL,
			expires_at INTEGER NOT NULL DEFAULT 0,
			created_at INTEGER NOT NULL,
			updated_at INTEGER NOT NULL,
			status TEXT NOT NULL DEFAULT 'active',
			comment TEXT DEFAULT '',
			pid INTEGER NOT NULL DEFAULT 0,
			UNIQUE(user_id)
		)`,
		`CREATE INDEX IF NOT EXISTS idx_instances_status ON instances(status)`,
		`CREATE INDEX IF NOT EXISTS idx_instances_expires ON instances(expires_at)`,
	}
	for _, q := range stmts {
		if _, err := s.db.Exec(q); err != nil {
			return err
		}
	}
	return nil
}

// Create inserts a new instance row.
func (s *Store) Create(i *Instance) error {
	now := time.Now().Unix()
	i.CreatedAt = now
	i.UpdatedAt = now
	_, err := s.db.Exec(`
		INSERT INTO instances(id, user_id, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid)
		VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
	`, i.ID, i.UserID, i.RoomID, i.CryptoKey, i.Provider, i.Transport, i.Token, i.DNS, i.ExpiresAt, i.CreatedAt, i.UpdatedAt, i.Status, i.Comment, i.PID)
	return err
}

// Update updates an existing instance.
func (s *Store) Update(i *Instance) error {
	i.UpdatedAt = time.Now().Unix()
	_, err := s.db.Exec(`
		UPDATE instances SET user_id=?, room_id=?, crypto_key=?, provider=?, transport=?, token=?, dns=?, expires_at=?, updated_at=?, status=?, comment=?, pid=?
		WHERE id=?
	`, i.UserID, i.RoomID, i.CryptoKey, i.Provider, i.Transport, i.Token, i.DNS, i.ExpiresAt, i.UpdatedAt, i.Status, i.Comment, i.PID, i.ID)
	return err
}

// GetByID fetches one instance by id.
func (s *Store) GetByID(id string) (*Instance, error) {
	row := s.db.QueryRow(`SELECT id, user_id, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid FROM instances WHERE id=?`, id)
	return scanInstance(row)
}

// GetByUser fetches the instance for the given user_id (if any).
func (s *Store) GetByUser(userID int64) (*Instance, error) {
	row := s.db.QueryRow(`SELECT id, user_id, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid FROM instances WHERE user_id=?`, userID)
	i, err := scanInstance(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return i, err
}

// List returns all instances.
func (s *Store) List() ([]*Instance, error) {
	rows, err := s.db.Query(`SELECT id, user_id, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid FROM instances ORDER BY created_at DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Instance
	for rows.Next() {
		i := &Instance{}
		if err := rows.Scan(&i.ID, &i.UserID, &i.RoomID, &i.CryptoKey, &i.Provider, &i.Transport, &i.Token, &i.DNS, &i.ExpiresAt, &i.CreatedAt, &i.UpdatedAt, &i.Status, &i.Comment, &i.PID); err != nil {
			return nil, err
		}
		out = append(out, i)
	}
	return out, rows.Err()
}

// Delete removes one instance row.
func (s *Store) Delete(id string) error {
	_, err := s.db.Exec(`DELETE FROM instances WHERE id=?`, id)
	return err
}

// ListExpiredActive returns active instances whose expires_at < ts.
func (s *Store) ListExpiredActive(ts int64) ([]*Instance, error) {
	rows, err := s.db.Query(`SELECT id, user_id, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid FROM instances WHERE status='active' AND expires_at>0 AND expires_at<?`, ts)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Instance
	for rows.Next() {
		i := &Instance{}
		if err := rows.Scan(&i.ID, &i.UserID, &i.RoomID, &i.CryptoKey, &i.Provider, &i.Transport, &i.Token, &i.DNS, &i.ExpiresAt, &i.CreatedAt, &i.UpdatedAt, &i.Status, &i.Comment, &i.PID); err != nil {
			return nil, err
		}
		out = append(out, i)
	}
	return out, rows.Err()
}

type scannable interface {
	Scan(dest ...interface{}) error
}

func scanInstance(r scannable) (*Instance, error) {
	i := &Instance{}
	err := r.Scan(&i.ID, &i.UserID, &i.RoomID, &i.CryptoKey, &i.Provider, &i.Transport, &i.Token, &i.DNS, &i.ExpiresAt, &i.CreatedAt, &i.UpdatedAt, &i.Status, &i.Comment, &i.PID)
	if err != nil {
		return nil, err
	}
	return i, nil
}
