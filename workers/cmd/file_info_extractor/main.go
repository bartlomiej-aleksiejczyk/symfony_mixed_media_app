package main

import (
	"log"

	"github.com/bartlomiej-aleksiejczyk/symfony_mixed_media_app/internal/config"
	"github.com/bartlomiej-aleksiejczyk/symfony_mixed_media_app/internal/db"
	"github.com/bartlomiej-aleksiejczyk/symfony_mixed_media_app/internal/file_info_extractor"
)

func main() {
	config.LoadEnv()

	host := config.GetEnv("DB_HOST", "localhost:5432")
	user := config.GetEnv("DB_USER", "youruser")
	password := config.GetEnv("DB_PASSWORD", "yourpassword")
	dbname := config.GetEnv("DB_NAME", "yourdb")
	mediaDir := config.GetEnv("WORKER_MEDIA_DIR", "../media")

	if err := db.Connect(host, user, password, dbname); err != nil {
		log.Fatal("Failed to connect to DB:", err)
	}
	defer db.Close()

	if err := db.CheckDBSchema(); err != nil {
		log.Fatal("Invalid DB schema: ", err)
	}

	file_info_extractor.StartWorker(mediaDir)

	log.Println("Graceful shutdown completed. Exiting.")
}
