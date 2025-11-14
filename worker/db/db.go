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

	pathLabelQuery := `
		CREATE TABLE IF NOT EXISTS path_label (
			id         BIGSERIAL NOT NULL,
			name       VARCHAR(255) NOT NULL,
			item_count INT NOT NULL,
			PRIMARY KEY (id)
		);
	`

	if _, err := db.Exec(pathLabelQuery); err != nil {
		return fmt.Errorf("failed to ensure path_label schema: %w", err)
	}

	return nil
}

// GetDB returns the database connection instance
func GetDB() *sql.DB {
	return db
}
