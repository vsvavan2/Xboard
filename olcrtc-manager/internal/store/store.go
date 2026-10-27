// Package store implements SQLite persistence for olcrtc instances.
package store

import (
	"database/sql"
	"errors"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

// Instance is the stored representation of one olcrtc srv instance.
//
// A single user owns several instances at once — one per "slot" — because the
// cabinet hands out one olcrtc:// key per Jitsi host:
//
//	slot 0 → primary host (meet.ffmuc.net)
//	slot 1 → mobile-carrier white-list bypass host (meet.egovm.ru)
//	slot 2 → spare host (meet.opensuse.org)
//
// Uniqueness is therefore (user_id, slot), never user_id alone.
type Instance struct {
	ID        string    `json:"id"`
	UserID    int64     `json:"user_id"`
	Slot      int       `json:"slot"`
	Host      string    `json:"host"`
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

const instanceCols = "id, user_id, slot, host, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid"

const createTableSQL = `CREATE TABLE IF NOT EXISTS instances (
	id TEXT PRIMARY KEY,
	user_id INTEGER NOT NULL DEFAULT 0,
	slot INTEGER NOT NULL DEFAULT 0,
	host TEXT NOT NULL DEFAULT '',
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
	UNIQUE(user_id, slot)
)`

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
	if err := s.ensureSchema(); err != nil {
		return err
	}
	for _, q := range []string{
		`CREATE INDEX IF NOT EXISTS idx_instances_status ON instances(status)`,
		`CREATE INDEX IF NOT EXISTS idx_instances_expires ON instances(expires_at)`,
	} {
		if _, err := s.db.Exec(q); err != nil {
			return err
		}
	}
	return nil
}

// ensureSchema creates the instances table on a fresh database and upgrades the
// legacy single-instance-per-user table (UNIQUE(user_id), no slot/host columns)
// in place, preserving every existing row as slot 0.
func (s *Store) ensureSchema() error {
	var name string
	err := s.db.QueryRow(`SELECT name FROM sqlite_master WHERE type='table' AND name='instances'`).Scan(&name)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		_, err = s.db.Exec(createTableSQL)
		return err
	case err != nil:
		return err
	}
	has, err := s.hasColumn("instances", "slot")
	if err != nil {
		return err
	}
	if has {
		return nil
	}
	return s.rebuildLegacy()
}

func (s *Store) hasColumn(table, col string) (bool, error) {
	rows, err := s.db.Query(`PRAGMA table_info(` + table + `)`)
	if err != nil {
		return false, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			cid     int
			name    string
			ctype   string
			notnull int
			dflt    sql.NullString
			pk      int
		)
		if err := rows.Scan(&cid, &name, &ctype, &notnull, &dflt, &pk); err != nil {
			return false, err
		}
		if name == col {
			return true, nil
		}
	}
	return false, rows.Err()
}

// rebuildLegacy recreates the instances table without UNIQUE(user_id) so a user
// can own several slots. SQLite cannot drop a table constraint, hence the
// rename + copy + drop dance.
func (s *Store) rebuildLegacy() error {
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()

	stmts := []string{
		`DROP INDEX IF EXISTS idx_instances_status`,
		`DROP INDEX IF EXISTS idx_instances_expires`,
		`ALTER TABLE instances RENAME TO instances_legacy`,
		createTableSQL,
		`INSERT INTO instances (id, user_id, slot, host, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid)
		 SELECT id, user_id, 0, '', room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid
		 FROM instances_legacy`,
		`DROP TABLE instances_legacy`,
	}
	for _, q := range stmts {
		if _, err := tx.Exec(q); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// Create inserts a new instance row.
func (s *Store) Create(i *Instance) error {
	now := time.Now().Unix()
	i.CreatedAt = now
	i.UpdatedAt = now
	_, err := s.db.Exec(`
		INSERT INTO instances(id, user_id, slot, host, room_id, crypto_key, provider, transport, token, dns, expires_at, created_at, updated_at, status, comment, pid)
		VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
	`, i.ID, i.UserID, i.Slot, i.Host, i.RoomID, i.CryptoKey, i.Provider, i.Transport, i.Token, i.DNS, i.ExpiresAt, i.CreatedAt, i.UpdatedAt, i.Status, i.Comment, i.PID)
	return err
}

// Update updates an existing instance.
func (s *Store) Update(i *Instance) error {
	i.UpdatedAt = time.Now().Unix()
	_, err := s.db.Exec(`
		UPDATE instances SET user_id=?, slot=?, host=?, room_id=?, crypto_key=?, provider=?, transport=?, token=?, dns=?, expires_at=?, updated_at=?, status=?, comment=?, pid=?
		WHERE id=?
	`, i.UserID, i.Slot, i.Host, i.RoomID, i.CryptoKey, i.Provider, i.Transport, i.Token, i.DNS, i.ExpiresAt, i.UpdatedAt, i.Status, i.Comment, i.PID, i.ID)
	return err
}

// GetByID fetches one instance by id.
func (s *Store) GetByID(id string) (*Instance, error) {
	row := s.db.QueryRow(`SELECT `+instanceCols+` FROM instances WHERE id=?`, id)
	return scanInstance(row)
}

// GetByUserSlot fetches the instance for a user's slot (if any).
func (s *Store) GetByUserSlot(userID int64, slot int) (*Instance, error) {
	row := s.db.QueryRow(`SELECT `+instanceCols+` FROM instances WHERE user_id=? AND slot=?`, userID, slot)
	i, err := scanInstance(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return i, err
}

// GetByUser fetches the primary (lowest slot) instance for the given user_id.
func (s *Store) GetByUser(userID int64) (*Instance, error) {
	row := s.db.QueryRow(`SELECT `+instanceCols+` FROM instances WHERE user_id=? ORDER BY slot ASC LIMIT 1`, userID)
	i, err := scanInstance(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return i, err
}

// ListByUser returns every instance of a user ordered by slot.
func (s *Store) ListByUser(userID int64) ([]*Instance, error) {
	rows, err := s.db.Query(`SELECT `+instanceCols+` FROM instances WHERE user_id=? ORDER BY slot ASC`, userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Instance
	for rows.Next() {
		i := &Instance{}
		if err := scanInstanceRow(rows, i); err != nil {
			return nil, err
		}
		out = append(out, i)
	}
	return out, rows.Err()
}

// List returns all instances.
func (s *Store) List() ([]*Instance, error) {
	rows, err := s.db.Query(`SELECT ` + instanceCols + ` FROM instances ORDER BY created_at DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Instance
	for rows.Next() {
		i := &Instance{}
		if err := scanInstanceRow(rows, i); err != nil {
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
	rows, err := s.db.Query(`SELECT `+instanceCols+` FROM instances WHERE status='active' AND expires_at>0 AND expires_at<?`, ts)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Instance
	for rows.Next() {
		i := &Instance{}
		if err := scanInstanceRow(rows, i); err != nil {
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
	if err := scanInstanceRow(r, i); err != nil {
		return nil, err
	}
	return i, nil
}

func scanInstanceRow(r scannable, i *Instance) error {
	return r.Scan(&i.ID, &i.UserID, &i.Slot, &i.Host, &i.RoomID, &i.CryptoKey, &i.Provider, &i.Transport, &i.Token, &i.DNS, &i.ExpiresAt, &i.CreatedAt, &i.UpdatedAt, &i.Status, &i.Comment, &i.PID)
}
