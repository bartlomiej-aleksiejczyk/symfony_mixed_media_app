package filehash

import (
	"crypto/sha256"
	"fmt"
	"io"
	"os"
)

// ComputeFileHash computes the SHA-256 hash of a file
func ComputeFileHash(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", fmt.Errorf("failed to open file %s: %v", path, err)
	}
	defer file.Close()

	hash := sha256.New()
	_, err = io.Copy(hash, file)
	if err != nil {
		return "", fmt.Errorf("failed to compute hash for file %s: %v", path, err)
	}

	return fmt.Sprintf("%x", hash.Sum(nil)), nil
}
