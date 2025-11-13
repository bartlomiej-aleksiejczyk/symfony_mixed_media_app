package main

import (
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/symfony_mixed_media_app/config"
	"github.com/symfony_mixed_media_app/db"
	"github.com/symfony_mixed_media_app/server"
	"github.com/symfony_mixed_media_app/worker"
)

func main() {
	// Load environment variables from .env file if it exists
	config.LoadEnv()

	// Retrieve DB configuration from environment variables
	host := config.GetEnv("DB_HOST", "localhost:5432")
	user := config.GetEnv("DB_USER", "youruser")
	password := config.GetEnv("DB_PASSWORD", "yourpassword")
	dbname := config.GetEnv("DB_NAME", "yourdb")
	mediaDir := config.GetEnv("WORKER_MEDIA_DIR", "../media")

	// Open DB connection
	err := db.Connect(host, user, password, dbname)
	if err != nil {
		log.Fatal("Failed to connect to DB:", err)
	}
	defer db.Close()

	// Check if schema is valid at start
	if err := db.CheckDBSchema(); err != nil {
		log.Fatal("Invalid DB schema: ", err)
	}

	// Create a new WorkerController instance
	workerController := worker.NewWorkerController()

	// Start the worker
	workerController.StartWorker(mediaDir)

	// Setup signal catching for graceful shutdown
	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, syscall.SIGINT, syscall.SIGTERM)

	// Control loop to interact with the worker
	go func() {
		for {
			select {
			case sig := <-stopChan:
				log.Printf("Received signal %s, stopping worker...", sig)
				workerController.StopWorker()
				return
			}
		}
	}()

	// Setup HTTP server and routes with Basic Authentication
	server.SetupRoutes(workerController)

	// Start the HTTP server
	server.StartServer()
}
