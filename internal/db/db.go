package db

import (
	"database/sql"
	"fmt"
	"time"

	"github.com/centromex/grocery-bot/internal/models"
	_ "github.com/mattn/go-sqlite3"
)

type DB struct {
	conn *sql.DB
}

// New creates a new encrypted SQLite database connection
func New(dbPath string, encryptionKey string) (*DB, error) {
	// SQLCipher connection string with encryption key
	connStr := fmt.Sprintf("%s?_pragma_key=%s&_pragma_cipher_page_size=4096", dbPath, encryptionKey)

	conn, err := sql.Open("sqlite3", connStr)
	if err != nil {
		return nil, fmt.Errorf("failed to open database: %w", err)
	}

	db := &DB{conn: conn}
	if err := db.migrate(); err != nil {
		return nil, fmt.Errorf("failed to migrate database: %w", err)
	}

	return db, nil
}

func (db *DB) migrate() error {
	schema := `
	CREATE TABLE IF NOT EXISTS requests (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		original_text TEXT NOT NULL,
		translated_text TEXT,
		budget TEXT,
		zone TEXT,
		status TEXT NOT NULL DEFAULT 'new',
		claimed_by INTEGER,
		claimed_by_name TEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		delivered_at DATETIME
	);

	CREATE TABLE IF NOT EXISTS volunteers (
		telegram_id INTEGER PRIMARY KEY,
		username TEXT,
		display_name TEXT,
		is_approved INTEGER DEFAULT 0,
		is_coordinator INTEGER DEFAULT 0,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	);

	CREATE TABLE IF NOT EXISTS addresses (
		request_id INTEGER PRIMARY KEY,
		address TEXT NOT NULL,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (request_id) REFERENCES requests(id)
	);

	CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status);
	CREATE INDEX IF NOT EXISTS idx_requests_claimed_by ON requests(claimed_by);
	`

	_, err := db.conn.Exec(schema)
	return err
}

// CreateRequest creates a new grocery request
func (db *DB) CreateRequest(originalText, budget, zone string) (*models.Request, error) {
	result, err := db.conn.Exec(
		`INSERT INTO requests (original_text, budget, zone, status) VALUES (?, ?, ?, ?)`,
		originalText, budget, zone, models.StatusNew,
	)
	if err != nil {
		return nil, err
	}

	id, err := result.LastInsertId()
	if err != nil {
		return nil, err
	}

	return &models.Request{
		ID:           id,
		OriginalText: originalText,
		Budget:       budget,
		Zone:         zone,
		Status:       models.StatusNew,
		CreatedAt:    time.Now(),
		UpdatedAt:    time.Now(),
	}, nil
}

// UpdateRequestTranslation updates the translated text and marks as posted
func (db *DB) UpdateRequestTranslation(id int64, translatedText string) error {
	_, err := db.conn.Exec(
		`UPDATE requests SET translated_text = ?, status = ?, updated_at = ? WHERE id = ?`,
		translatedText, models.StatusPosted, time.Now(), id,
	)
	return err
}

// ClaimRequest marks a request as claimed by a volunteer
func (db *DB) ClaimRequest(requestID int64, volunteerID int64, volunteerName string) error {
	result, err := db.conn.Exec(
		`UPDATE requests SET status = ?, claimed_by = ?, claimed_by_name = ?, updated_at = ?
		 WHERE id = ? AND status = ?`,
		models.StatusClaimed, volunteerID, volunteerName, time.Now(), requestID, models.StatusPosted,
	)
	if err != nil {
		return err
	}

	rows, err := result.RowsAffected()
	if err != nil {
		return err
	}
	if rows == 0 {
		return fmt.Errorf("request not available for claiming")
	}

	return nil
}

// CompleteRequest marks a request as delivered and deletes the address
func (db *DB) CompleteRequest(requestID int64, volunteerID int64) error {
	tx, err := db.conn.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	// Verify the volunteer owns this claim
	var claimedBy int64
	err = tx.QueryRow(`SELECT claimed_by FROM requests WHERE id = ?`, requestID).Scan(&claimedBy)
	if err != nil {
		return err
	}
	if claimedBy != volunteerID {
		return fmt.Errorf("you don't have this request claimed")
	}

	// Mark as delivered
	now := time.Now()
	_, err = tx.Exec(
		`UPDATE requests SET status = ?, delivered_at = ?, updated_at = ? WHERE id = ?`,
		models.StatusDelivered, now, now, requestID,
	)
	if err != nil {
		return err
	}

	// Delete the address immediately
	_, err = tx.Exec(`DELETE FROM addresses WHERE request_id = ?`, requestID)
	if err != nil {
		return err
	}

	return tx.Commit()
}

// GetRequest retrieves a request by ID
func (db *DB) GetRequest(id int64) (*models.Request, error) {
	var req models.Request
	var deliveredAt sql.NullTime
	var claimedBy sql.NullInt64
	var claimedByName sql.NullString

	err := db.conn.QueryRow(
		`SELECT id, original_text, translated_text, budget, zone, status,
		        claimed_by, claimed_by_name, created_at, updated_at, delivered_at
		 FROM requests WHERE id = ?`, id,
	).Scan(
		&req.ID, &req.OriginalText, &req.TranslatedText, &req.Budget, &req.Zone,
		&req.Status, &claimedBy, &claimedByName, &req.CreatedAt, &req.UpdatedAt, &deliveredAt,
	)
	if err != nil {
		return nil, err
	}

	if claimedBy.Valid {
		req.ClaimedBy = claimedBy.Int64
	}
	if claimedByName.Valid {
		req.ClaimedByName = claimedByName.String
	}
	if deliveredAt.Valid {
		req.DeliveredAt = &deliveredAt.Time
	}

	return &req, nil
}

// GetOpenRequests returns all requests that are posted but not claimed
func (db *DB) GetOpenRequests() ([]models.Request, error) {
	rows, err := db.conn.Query(
		`SELECT id, original_text, translated_text, budget, zone, status, created_at, updated_at
		 FROM requests WHERE status = ? ORDER BY created_at ASC`, models.StatusPosted,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var requests []models.Request
	for rows.Next() {
		var req models.Request
		err := rows.Scan(
			&req.ID, &req.OriginalText, &req.TranslatedText, &req.Budget, &req.Zone,
			&req.Status, &req.CreatedAt, &req.UpdatedAt,
		)
		if err != nil {
			return nil, err
		}
		requests = append(requests, req)
	}

	return requests, nil
}

// GetVolunteerRequests returns requests claimed by a specific volunteer
func (db *DB) GetVolunteerRequests(volunteerID int64) ([]models.Request, error) {
	rows, err := db.conn.Query(
		`SELECT id, original_text, translated_text, budget, zone, status, created_at, updated_at
		 FROM requests WHERE claimed_by = ? AND status IN (?, ?) ORDER BY created_at DESC`,
		volunteerID, models.StatusClaimed, models.StatusShopping,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var requests []models.Request
	for rows.Next() {
		var req models.Request
		err := rows.Scan(
			&req.ID, &req.OriginalText, &req.TranslatedText, &req.Budget, &req.Zone,
			&req.Status, &req.CreatedAt, &req.UpdatedAt,
		)
		if err != nil {
			return nil, err
		}
		requests = append(requests, req)
	}

	return requests, nil
}

// SaveAddress stores a delivery address (encrypted with the DB)
func (db *DB) SaveAddress(requestID int64, address string) error {
	_, err := db.conn.Exec(
		`INSERT OR REPLACE INTO addresses (request_id, address, created_at) VALUES (?, ?, ?)`,
		requestID, address, time.Now(),
	)
	return err
}

// GetAddress retrieves the address for a request
func (db *DB) GetAddress(requestID int64) (string, error) {
	var address string
	err := db.conn.QueryRow(`SELECT address FROM addresses WHERE request_id = ?`, requestID).Scan(&address)
	return address, err
}

// AddVolunteer adds or updates a volunteer
func (db *DB) AddVolunteer(telegramID int64, username, displayName string, isApproved bool) error {
	_, err := db.conn.Exec(
		`INSERT OR REPLACE INTO volunteers (telegram_id, username, display_name, is_approved, created_at)
		 VALUES (?, ?, ?, ?, ?)`,
		telegramID, username, displayName, isApproved, time.Now(),
	)
	return err
}

// IsVolunteerApproved checks if a user is an approved volunteer
func (db *DB) IsVolunteerApproved(telegramID int64) (bool, error) {
	var isApproved bool
	err := db.conn.QueryRow(
		`SELECT is_approved FROM volunteers WHERE telegram_id = ?`, telegramID,
	).Scan(&isApproved)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return isApproved, err
}

// IsCoordinator checks if a user is a coordinator
func (db *DB) IsCoordinator(telegramID int64) (bool, error) {
	var isCoordinator bool
	err := db.conn.QueryRow(
		`SELECT is_coordinator FROM volunteers WHERE telegram_id = ?`, telegramID,
	).Scan(&isCoordinator)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return isCoordinator, err
}

// PurgeOldRequests deletes delivered requests older than the specified duration
func (db *DB) PurgeOldRequests(olderThan time.Duration) (int64, error) {
	cutoff := time.Now().Add(-olderThan)
	result, err := db.conn.Exec(
		`DELETE FROM requests WHERE status = ? AND delivered_at < ?`,
		models.StatusDelivered, cutoff,
	)
	if err != nil {
		return 0, err
	}
	return result.RowsAffected()
}

// Close closes the database connection
func (db *DB) Close() error {
	return db.conn.Close()
}
