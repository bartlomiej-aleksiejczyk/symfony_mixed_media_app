package worker

import (
	"log"
	"time"
)

// WorkerController manages the state of the worker
type WorkerController struct {
	controlChannel chan string
	statusChannel  chan string
	running        bool
}

// NewWorkerController creates and initializes a WorkerController
func NewWorkerController() *WorkerController {
	return &WorkerController{
		controlChannel: make(chan string),
		statusChannel:  make(chan string),
		running:        false,
	}
}

// StartWorker starts the worker with the given directory and controls it using the WorkerController
func (wc *WorkerController) StartWorker(directory string) {
	go func() {
		for {
			select {
			case cmd := <-wc.controlChannel:
				switch cmd {
				case "stop":
					wc.running = false
					log.Println("Stopping the worker.")
					return
				case "force-start":
					log.Println("Force starting the worker.")
				default:
					log.Println("Unrecognized command.")
				}
			default:
				if !wc.running {
					log.Println("Starting the worker.")
					wc.running = true
					StartWorker(directory)
				}
				time.Sleep(1 * time.Second) // Check every second
			}
		}
	}()
}

// GetWorkerStatus checks and returns the current worker status
func (wc *WorkerController) GetWorkerStatus() string {
	if wc.running {
		return "Worker is running."
	}
	return "Worker is stopped."
}

// StopWorker stops the worker
func (wc *WorkerController) StopWorker() {
	if wc.running {
		wc.controlChannel <- "stop"
		wc.running = false
	}
}

// ForceStartWorker force starts the worker, overriding any current state
func (wc *WorkerController) ForceStartWorker() {
	if !wc.running {
		wc.controlChannel <- "force-start"
		wc.running = true
	}
}
