package file_info_extractor

import (
	"database/sql"
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/bartlomiej-aleksiejczyk/symfony_mixed_media_app/internal/db"
	"github.com/bartlomiej-aleksiejczyk/symfony_mixed_media_app/internal/filehash"
)

func StartWorker(directory string) {
	log.Printf("[worker] worker loop started for directory=%s", directory)

	start := time.Now()
	scanTimestamp := time.Now().UTC().Truncate(time.Second)

	err := filepath.Walk(directory, func(path string, info os.FileInfo, err error) error {
		return processFile(directory, path, info, err, scanTimestamp)
	})
	if err != nil {
		log.Printf("[worker] Error traversing directory: %v", err)
	}

	cleanupDeletedFiles(scanTimestamp)
	cleanupUnusedTags()

	log.Printf("[worker] cycle finished in %v", time.Since(start))
}

func processFile(baseDir, path string, info os.FileInfo, err error, scanTimestamp time.Time) error {
	if err != nil {
		log.Printf("Error accessing file %s: %v", path, err)
		return nil
	}

	if info.IsDir() {
		return nil
	}

	size := info.Size()
	modifiedTime := info.ModTime().Truncate(time.Second)

	var (
		fileID         int64
		dbHash         string
		dbSize         int64
		dbLastSeen     time.Time
		dbModifiedTime time.Time
	)

	queryErr := db.GetDB().QueryRow(
		"SELECT id, hash, size, last_seen, modified_time FROM filesystem_file WHERE path = $1",
		path,
	).Scan(&fileID, &dbHash, &dbSize, &dbLastSeen, &dbModifiedTime)

	dbModifiedTimeNorm := dbModifiedTime.UTC().Truncate(time.Second)

	switch {
	case queryErr == sql.ErrNoRows:
		hash, err := filehash.ComputeFileHash(path)
		if err != nil {
			log.Printf("Error computing hash for file %s: %v", path, err)
			return nil
		}

		err = db.GetDB().QueryRow(
			`INSERT INTO filesystem_file (path, hash, size, last_seen, modified_time)
			 VALUES ($1, $2, $3, $4, $5)
			 RETURNING id`,
			path, hash, size, scanTimestamp, modifiedTime,
		).Scan(&fileID)
		if err != nil {
			log.Printf("Error inserting file into DB: %v", err)
			return nil
		}

	case queryErr != nil:
		// Real query error
		log.Printf("Error querying DB for file %s: %v", path, queryErr)
		return nil

	default:
		// File exists
		if dbSize != size || !dbModifiedTimeNorm.Equal(modifiedTime) {
			// Modified file: recompute hash, insert new row (your logic)
			hash, err := filehash.ComputeFileHash(path)
			if err != nil {
				log.Printf("Error computing hash for file %s: %v", path, err)
				return nil
			}

			err = db.GetDB().QueryRow(
				`INSERT INTO filesystem_file (path, hash, size, last_seen, modified_time)
				 VALUES ($1, $2, $3, $4, $5)
				 RETURNING id`,
				path, hash, size, scanTimestamp, modifiedTime,
			).Scan(&fileID)
			if err != nil {
				log.Printf("Error inserting updated file into DB: %v", err)
				return nil
			}
		} else {
			// Unchanged file: just update last_seen
			_, err := db.GetDB().Exec(
				"UPDATE filesystem_file SET last_seen = $1 WHERE id = $2",
				scanTimestamp, fileID,
			)
			if err != nil {
				log.Printf("Error updating last_seen for file %s: %v", path, err)
			}
		}
	}

	// At this point we have a valid fileID -> sync path-derived tags
	if err := syncPathTagsForFile(dbHash, baseDir, path); err != nil {
		log.Printf("[tags] error syncing path tags for file %s: %v", path, err)
	}

	return nil
}

// cleanupDeletedFiles removes files from the DB that were not seen in the current scan
func cleanupDeletedFiles(scanTimestamp time.Time) {
	_, err := db.GetDB().Exec(`
		DELETE FROM filesystem_file
		WHERE last_seen != $1
	`, scanTimestamp)
	if err != nil {
		log.Printf("Error cleaning up deleted files: %v", err)
	}
}

// syncPathTagsForFile derives tags from the file path and stores them in tag/media_file_tag
func syncPathTagsForFile(dbHash string, baseDir, fullPath string) error {
	var mediaID string

	rel, err := filepath.Rel(baseDir, fullPath)
	if err != nil {
		rel = fullPath
	}

	dir := filepath.Dir(rel)
	if dir == "." || dir == string(os.PathSeparator) {
		return nil
	}

	queryErr := db.GetDB().QueryRow(
		"SELECT id FROM media_file WHERE hash = $1",
		dbHash,
	).Scan(mediaID)

	if queryErr != nil {
		log.Printf("Error searching for media files during the tag sync: %v", queryErr)
	}

	segments := strings.Split(dir, string(os.PathSeparator))

	for _, raw := range segments {
		name := strings.TrimSpace(raw)
		if name == "" || name == "." {
			continue
		}

		// Path tags are always created as is_managed = FALSE.
		// ON CONFLICT: don't touch is_managed, just keep existing value.
		var tagID int64
		err := db.GetDB().QueryRow(
			`INSERT INTO tag (name, is_managed)
			 VALUES ($1, FALSE)
			 ON CONFLICT (name) DO UPDATE
				SET name = EXCLUDED.name
			 RETURNING id`,
			name,
		).Scan(&tagID)
		if err != nil {
			log.Printf("[tags] error upserting tag %q: %v", name, err)
			continue
		}

		_, err = db.GetDB().Exec(
			`INSERT INTO media_file_tag (media_file_id, tag_id)
			 VALUES ($1, $2)
			 ON CONFLICT (media_file_id, tag_id) DO NOTHING`,
			mediaID, tagID,
		)
		if err != nil {
			log.Printf("[tags] error linking file %d with tag %d (%q): %v", mediaID, tagID, name, err)
			continue
		}
	}

	return nil
}

func cleanupUnusedTags() {
	_, err := db.GetDB().Exec(`
		DELETE FROM tag t
		WHERE t.is_managed = FALSE
		  AND NOT EXISTS (
		      SELECT 1
		      FROM media_file_tag mft
		      WHERE mft.tag_id = t.id
		  );
	`)
	if err != nil {
		log.Printf("[tags] error cleaning up unused tags: %v", err)
	}
}
