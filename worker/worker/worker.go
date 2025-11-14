package worker

import (
	"context"
	"database/sql"
	"log"
	"os"
	"path/filepath"
	"time"

	"github.com/symfony_mixed_media_app/db"
	"github.com/symfony_mixed_media_app/filehash"
)

// TODO: consider running the file processing in parallel using goroutines

func StartWorker(ctx context.Context, directory string) {
	log.Printf("[worker] worker loop started for directory=%s", directory)

	for {
		start := time.Now()
		scanTimestamp := time.Now().UTC().Truncate(time.Second)

		err := filepath.Walk(directory, func(path string, info os.FileInfo, err error) error {
			return processFile(path, info, err, scanTimestamp)
		})
		if err != nil {
			log.Printf("[worker] Error traversing directory: %v", err)
		}

		cleanupDeletedFiles(scanTimestamp)

		if err := RebuildPathCategories(directory); err != nil {
			log.Printf("[path-category] error rebuilding index: %v", err)
		}

		log.Printf("[worker] cycle finished in %v", time.Since(start))

		select {
		case <-ctx.Done():
			log.Println("[worker] context cancelled, exiting worker loop")
			return
		case <-time.After(1 * time.Hour):
		}
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
	modifiedTime := info.ModTime().Truncate(time.Second)

	// Query DB to see if file exists
	var fileID int
	var dbHash string
	var dbSize int64
	var dbLastSeen time.Time
	var dbModifiedTime time.Time
	err = db.GetDB().QueryRow("SELECT id, hash, size, last_seen, modified_time FROM filesystem_file WHERE path = $1", path).Scan(&fileID, &dbHash, &dbSize, &dbLastSeen, &dbModifiedTime)
	dbModifiedTimeNorm := dbModifiedTime.UTC().Truncate(time.Second)

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

		if dbSize != size || !dbModifiedTimeNorm.Equal(modifiedTime) {
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
