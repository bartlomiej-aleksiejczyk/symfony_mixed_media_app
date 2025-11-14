package worker

import (
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"

	"github.com/symfony_mixed_media_app/db"
)

func RebuildPathCategories(mediaRoot string) error {
	conn := db.GetDB()
	if conn == nil {
		return fmt.Errorf("db connection is nil")
	}

	log.Printf("[path-category] rebuilding index for mediaRoot=%s", mediaRoot)

	// 1) Read all paths from filesystem_file
	rows, err := conn.Query(`SELECT path FROM filesystem_file`)
	if err != nil {
		return fmt.Errorf("failed to query filesystem_file: %w", err)
	}
	defer rows.Close()

	// name -> count
	counts := make(map[string]int64)

	for rows.Next() {
		var fullPath string
		if err := rows.Scan(&fullPath); err != nil {
			return fmt.Errorf("failed to scan path: %w", err)
		}

		// 2) Make path relative to mediaRoot
		rel, err := filepath.Rel(mediaRoot, fullPath)
		if err != nil {
			log.Printf("[path-category] skipping path=%q: rel error: %v", fullPath, err)
			continue
		}

		// If not under mediaRoot, skip (Rel can produce ../something)
		if strings.HasPrefix(rel, "..") {
			log.Printf("[path-category] skipping path=%q: outside mediaRoot", fullPath)
			continue
		}

		// dir part of relative path, e.g. "funny/lighthearted"
		dir := filepath.Dir(rel)
		if dir == "." || dir == string(os.PathSeparator) {
			// File directly in root -> no category
			continue
		}

		// 3) Split into directories: "funny/lighthearted" -> ["funny","lighthearted"]
		segments := strings.Split(dir, string(os.PathSeparator))
		for _, seg := range segments {
			seg = strings.TrimSpace(seg)
			if seg == "" || seg == "." {
				continue
			}
			counts[seg]++
		}
	}

	if err := rows.Err(); err != nil {
		return fmt.Errorf("rows error: %w", err)
	}

	// 4) Rebuild path_label table atomically
	tx, err := conn.Begin()
	if err != nil {
		return fmt.Errorf("failed to begin tx: %w", err)
	}

	// Clear existing data (we fully recompute from filesystem_file)
	if _, err := tx.Exec(`DELETE FROM path_label`); err != nil {
		_ = tx.Rollback()
		return fmt.Errorf("failed to clear path_label: %w", err)
	}

	stmt, err := tx.Prepare(`INSERT INTO path_label (name, item_count) VALUES ($1, $2)`)
	if err != nil {
		_ = tx.Rollback()
		return fmt.Errorf("failed to prepare insert into path_label: %w", err)
	}
	defer stmt.Close()

	for name, cnt := range counts {
		if _, err := stmt.Exec(name, cnt); err != nil {
			_ = tx.Rollback()
			return fmt.Errorf("failed to insert path_label (%s, %d): %w", name, cnt, err)
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("failed to commit path_label rebuild: %w", err)
	}

	log.Printf("[path-category] rebuild complete. categories=%d", len(counts))
	return nil
}
