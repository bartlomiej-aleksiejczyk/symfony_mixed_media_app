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

// CheckDBSchema checks if the filesystem_file table exists and has the correct schema
func CheckDBSchema() error {
	query := `
		CREATE TABLE IF NOT EXISTS filesystem_file (
			id 				BIGSERIAL PRIMARY KEY NOT NULL,
			path 			VARCHAR(4096) NOT NULL,
			hash 			VARCHAR(128) NOT NULL,
			size 			BIGINT NOT NULL,
			last_seen 		TIMESTAMP(0) WITH TIME ZONE NOT NULL
			modified_time   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
		);
	`
	_, err := db.Exec(query)
	if err != nil {
		return fmt.Errorf("failed to ensure schema: %v", err)
	}
	return nil
}

// GetDB returns the database connection instance
func GetDB() *sql.DB {
	return db
}
