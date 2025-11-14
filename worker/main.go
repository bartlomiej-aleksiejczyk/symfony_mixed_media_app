// main.go
package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/symfony_mixed_media_app/config"
	"github.com/symfony_mixed_media_app/db"
	"github.com/symfony_mixed_media_app/server"
	"github.com/symfony_mixed_media_app/worker"
)

func main() {
	config.LoadEnv()

	host := config.GetEnv("DB_HOST", "localhost:5432")
	user := config.GetEnv("DB_USER", "youruser")
	password := config.GetEnv("DB_PASSWORD", "yourpassword")
	dbname := config.GetEnv("DB_NAME", "yourdb")
	mediaDir := config.GetEnv("WORKER_MEDIA_DIR", "../media")

	// Connect DB
	if err := db.Connect(host, user, password, dbname); err != nil {
		log.Fatal("Failed to connect to DB:", err)
	}
	defer db.Close()

	if err := db.CheckDBSchema(); err != nil {
		log.Fatal("Invalid DB schema: ", err)
	}

	// Create controller & start worker
	workerController := worker.NewWorkerController(mediaDir)
	workerController.Start()

	// HTTP server: adjust depending on your server package
	// Here we assume server.StartServer returns an *http.Server
	httpSrv := server.SetupRoutesAndServer(workerController) // you define this
	go func() {
		log.Println("Starting server on :8080")
		if err := httpSrv.ListenAndServe(); err != nil && err.Error() != "http: Server closed" {
			log.Fatalf("HTTP server error: %v", err)
		}
	}()

	// Signal handling
	signals := make(chan os.Signal, 2)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)

	// First signal: graceful shutdown
	sig := <-signals
	log.Printf("Received signal %s. Starting graceful shutdown. Press Ctrl+C again to force exit.", sig)

	// Stop worker gracefully
	workerController.Stop()

	// Stop HTTP server with timeout
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := httpSrv.Shutdown(shutdownCtx); err != nil {
		log.Printf("HTTP server shutdown error: %v", err)
	}

	// DB will be closed by defer

	// Second signal: hard exit if something hangs
	go func() {
		sig2 := <-signals
		log.Printf("Received second signal %s. Forcing exit now.", sig2)
		os.Exit(1)
	}()

	log.Println("Graceful shutdown completed. Exiting.")
}
