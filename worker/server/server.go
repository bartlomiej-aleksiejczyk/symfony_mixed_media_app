package server

import (
	"encoding/base64"
	"encoding/json"
	"log"
	"net/http"
	"strings"

	"github.com/symfony_mixed_media_app/config"
	"github.com/symfony_mixed_media_app/worker"
)

func SetupRoutesAndServer(wc *worker.WorkerController) *http.Server {
	mux := http.NewServeMux()
	// register handlers using wc, e.g. /status, /force-start, etc.

	return &http.Server{
		Addr:    ":8080",
		Handler: mux,
	}
}
func BasicAuthMiddleware(next http.Handler) http.Handler {
	// Retrieve login and password from environment variables
	login := config.GetEnv("WORKER_LOGIN", "admin")
	password := config.GetEnv("WORKER_PASSWORD", "password")

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Get Authorization header
		authHeader := r.Header.Get("Authorization")
		if authHeader == "" {
			http.Error(w, "Authorization header required", http.StatusUnauthorized)
			return
		}

		// Extract the credentials from the header
		parts := strings.SplitN(authHeader, " ", 2)
		if len(parts) != 2 || parts[0] != "Basic" {
			http.Error(w, "Invalid Authorization header", http.StatusUnauthorized)
			return
		}

		// Decode the base64 credentials
		decoded, err := base64.StdEncoding.DecodeString(parts[1])
		if err != nil {
			http.Error(w, "Invalid Authorization header", http.StatusUnauthorized)
			return
		}

		// Split into login and password
		creds := strings.SplitN(string(decoded), ":", 2)
		if len(creds) != 2 || creds[0] != login || creds[1] != password {
			http.Error(w, "Invalid credentials", http.StatusUnauthorized)
			return
		}

		// Proceed to the next handler if authentication is successful
		next.ServeHTTP(w, r)
	})
}

// statusHandler to get the worker status
func statusHandler(w http.ResponseWriter, r *http.Request, wc *worker.WorkerController) {
	status := wc.GetWorkerStatus()
	response := map[string]string{"status": status}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(response)
}

// startHandler to start the worker
func startHandler(w http.ResponseWriter, r *http.Request, wc *worker.WorkerController) {
	wc.ForceStartWorker()
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"message": "Worker started"})
}

// stopHandler to stop the worker
func stopHandler(w http.ResponseWriter, r *http.Request, wc *worker.WorkerController) {
	wc.Stop()
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"message": "Worker stopped"})
}

// SetupRoutes sets up all the HTTP routes and applies BasicAuthMiddleware
func SetupRoutes(wc *worker.WorkerController) {

	// Apply BasicAuthMiddleware to all the handlers
	http.Handle("/status", BasicAuthMiddleware(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		statusHandler(w, r, wc)
	})))
	http.Handle("/start", BasicAuthMiddleware(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		startHandler(w, r, wc)
	})))
	http.Handle("/stop", BasicAuthMiddleware(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		stopHandler(w, r, wc)
	})))
}

// StartServer starts the HTTP server
func StartServer() {
	log.Println("Starting server on :8080")
	if err := http.ListenAndServe(":8080", nil); err != nil {
		log.Fatal("Error starting HTTP server: ", err)
	}
}
