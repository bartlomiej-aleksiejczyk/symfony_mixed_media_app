package db

import (
	"database/sql"
	"fmt"
	"log"

	_ "github.com/lib/pq"
)

var db *sql.DB

// Connect initializes the DB connection
func Connect(host, user, password, dbname string) error {
	connStr := fmt.Sprintf("postgres://%s:%s@%s/%s?sslmode=disable", user, password, host, dbname)
	var err error
	db, err = sql.Open("postgres", connStr)
	if err != nil {
		return fmt.Errorf("failed to open DB connection: %v", err)
	}
	return nil
}

// Close closes the DB connection
func Close() {
	if err := db.Close(); err != nil {
		log.Println("Failed to close DB connection:", err)
	}
}

func CheckDBSchema() error {
	filesystemFileQuery := `
		CREATE TABLE IF NOT EXISTS filesystem_file (
			id 				BIGSERIAL PRIMARY KEY NOT NULL,
			path 			VARCHAR(4096) NOT NULL,
			hash 			VARCHAR(128) NOT NULL,
			size 			BIGINT NOT NULL,
			last_seen 		TIMESTAMP(0) WITH TIME ZONE NOT NULL,
			modified_time   TIMESTAMP(0) WITH TIME ZONE NOT NULL
		);
	`
	if _, err := db.Exec(filesystemFileQuery); err != nil {
		return fmt.Errorf("failed to ensure filesystem_file schema: %w", err)
	}

	// tag table with is_managed + UNIQUE(name)
	tagTableQuery := `
		CREATE TABLE IF NOT EXISTS tag (
			id         BIGSERIAL PRIMARY KEY NOT NULL,
			name       VARCHAR(255) NOT NULL,
			is_managed BOOLEAN NOT NULL DEFAULT FALSE
		);
	`
	if _, err := db.Exec(tagTableQuery); err != nil {
		return fmt.Errorf("failed to ensure tag schema: %w", err)
	}

	// media_file_tag join table
	mediaFileTagQuery := `
		CREATE TABLE IF NOT EXISTS media_file_tag (
			media_file_id BIGINT NOT NULL,
			tag_id        BIGINT NOT NULL,
			PRIMARY KEY (media_file_id, tag_id),
			CONSTRAINT fk_media_file_tag_file
				FOREIGN KEY (media_file_id) REFERENCES filesystem_file(id) ON DELETE CASCADE,
			CONSTRAINT fk_media_file_tag_tag
				FOREIGN KEY (tag_id) REFERENCES tag(id) ON DELETE CASCADE
		);
	`
	if _, err := db.Exec(mediaFileTagQuery); err != nil {
		return fmt.Errorf("failed to ensure media_file_tag schema: %w", err)
	}

	return nil
}

// GetDB returns the database connection instance
func GetDB() *sql.DB {
	return db
}
