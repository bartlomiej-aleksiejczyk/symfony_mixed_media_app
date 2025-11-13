package worker

import (
	"database/sql"
	"log"
	"os"
	"path/filepath"
	"time"

	"github.com/symfony_mixed_media_app/db"
	"github.com/symfony_mixed_media_app/filehash"
)

// TODO: consider running the file processing in parallel using goroutines

// StartWorker starts the worker that processes the media directory
func StartWorker(directory string) {
	for {
		start := time.Now()
		// Set a single scan timestamp for the whole scan
		scanTimestamp := time.Now()

		// Traverse directory and process files
		err := filepath.Walk(directory, func(path string, info os.FileInfo, err error) error {
			return processFile(path, info, err, scanTimestamp)
		})

		if err != nil {
			log.Printf("Error traversing directory: %v", err)
		}

		// Cleanup deleted files (files not seen in the current scan)
		cleanupDeletedFiles(scanTimestamp)

		log.Printf("Worker finished in %v. Waiting for next scan.", time.Since(start))

		// Wait for 1 hour before starting another scan
		time.Sleep(1 * time.Hour)
	}
}

// processFile processes a single file (compute hash and update DB)
func processFile(path string, info os.FileInfo, err error, scanTimestamp time.Time) error {
	if err != nil {
		log.Printf("Error accessing file %s: %v", path, err)
		return nil
	}

	// Skip directories
	if info.IsDir() {
		return nil
	}

	// Get file size and modification time
	size := info.Size()
	modifiedTime := info.ModTime()

	// Query DB to see if file exists
	var fileID int
	var dbHash string
	var dbSize int64
	var dbLastSeen time.Time
	var dbModifiedTime time.Time
	err = db.GetDB().QueryRow("SELECT id, hash, size, last_seen, modified_time FROM filesystem_file WHERE path = $1", path).Scan(&fileID, &dbHash, &dbSize, &dbLastSeen, &dbModifiedTime)

	if err == sql.ErrNoRows {
		// File doesn't exist, insert new
		hash, err := filehash.ComputeFileHash(path)
		if err != nil {
			log.Printf("Error computing hash for file %s: %v", path, err)
			return nil
		}

		_, err = db.GetDB().Exec(
			"INSERT INTO filesystem_file (path, hash, size, last_seen, modified_time) VALUES ($1, $2, $3, $4, $5)",
			path, hash, size, scanTimestamp, modifiedTime,
		)
		if err != nil {
			log.Printf("Error inserting file into DB: %v", err)
		}
	} else if err != nil {
		log.Printf("Error querying DB for file %s: %v", path, err)
	} else {
		// File exists, check if modified/renamed
		if dbSize != size || dbModifiedTime != modifiedTime {
			// File is modified, need to update
			hash, err := filehash.ComputeFileHash(path)
			if err != nil {
				log.Printf("Error computing hash for file %s: %v", path, err)
				return nil
			}

			_, err = db.GetDB().Exec(
				"INSERT INTO filesystem_file (path, hash, size, last_seen, modified_time) VALUES ($1, $2, $3, $4, $5)",
				path, hash, size, scanTimestamp, modifiedTime,
			)
			if err != nil {
				log.Printf("Error updating file in DB: %v", err)
			}
		} else {
			// File is not modified (path, size, and modified_time are the same), just update last_seen
			_, err := db.GetDB().Exec(
				"UPDATE filesystem_file SET last_seen = $1 WHERE id = $2",
				scanTimestamp, fileID,
			)
			if err != nil {
				log.Printf("Error updating last_seen for file %s: %v", path, err)
			}
		}
	}

	return nil
}

// cleanupDeletedFiles removes files from the DB that were not seen in the current scan
func cleanupDeletedFiles(scanTimestamp time.Time) {
	// Delete files that were not seen in the current scan
	_, err := db.GetDB().Exec(`
		DELETE FROM filesystem_file
		WHERE last_seen != $1
	`, scanTimestamp)
	if err != nil {
		log.Printf("Error cleaning up deleted files: %v", err)
	}
}
