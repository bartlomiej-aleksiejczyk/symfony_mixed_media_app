// worker/controller.go
package worker

import (
	"context"
	"log"
	"sync"
)

type WorkerController struct {
	mu        sync.Mutex
	running   bool
	cancel    context.CancelFunc
	wg        sync.WaitGroup
	directory string
}

func NewWorkerController(directory string) *WorkerController {
	return &WorkerController{
		directory: directory,
	}
}

// Start starts the worker if it's not already running.
func (wc *WorkerController) Start() {
	wc.mu.Lock()
	defer wc.mu.Unlock()

	if wc.running {
		log.Println("[worker-controller] worker already running")
		return
	}

	ctx, cancel := context.WithCancel(context.Background())
	wc.cancel = cancel
	wc.running = true

	wc.wg.Add(1)
	go func() {
		defer wc.wg.Done()
		// use the directory stored in the controller
		StartWorker(ctx, wc.directory)

		// When worker exits, mark as not running
		wc.mu.Lock()
		wc.running = false
		wc.mu.Unlock()
	}()
	log.Println("[worker-controller] worker started")
}

// Stop asks the worker to stop and waits for it.
func (wc *WorkerController) Stop() {
	wc.mu.Lock()
	if !wc.running || wc.cancel == nil {
		wc.mu.Unlock()
		log.Println("[worker-controller] Stop called but worker not running")
		return
	}
	cancel := wc.cancel
	wc.mu.Unlock()

	log.Println("[worker-controller] stopping worker gracefully...")
	cancel()
	wc.wg.Wait()
	log.Println("[worker-controller] worker stopped")
}

func (wc *WorkerController) GetWorkerStatus() string {
	wc.mu.Lock()
	defer wc.mu.Unlock()
	if wc.running {
		return "Worker is running."
	}
	return "Worker is stopped."
}

// Optional: restart regardless of state
func (wc *WorkerController) ForceStartWorker() {
	wc.Stop()
	wc.Start()
}
