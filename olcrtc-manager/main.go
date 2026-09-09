// Package main is the olcrtc-manager entrypoint.
// olcrtc-manager is a REST API service that manages olcrtc server instances.
package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/gin-contrib/cors"
	"github.com/gin-gonic/gin"

	"olcrtc-manager/internal/api"
	"olcrtc-manager/internal/config"
	"olcrtc-manager/internal/manager"
	"olcrtc-manager/internal/store"
)

func main() {
	cfg := config.Load()

	s, err := store.New(cfg.DatabasePath)
	if err != nil {
		log.Fatalf("failed to init store: %v", err)
	}
	defer s.Close()

	m, err := manager.New(cfg, s)
	if err != nil {
		log.Fatalf("failed to init manager: %v", err)
	}
	go m.Watch()

	h := api.NewHandler(m, cfg.APIKey)

	gin.SetMode(gin.ReleaseMode)
	r := gin.New()
	r.Use(gin.Recovery())
	r.Use(cors.New(cors.Config{
		AllowOrigins:     []string{"*"},
		AllowMethods:     []string{"GET", "POST", "DELETE", "PUT", "OPTIONS"},
		AllowHeaders:     []string{"*"},
		ExposeHeaders:    []string{"Content-Length"},
		AllowCredentials: true,
		MaxAge:           12 * time.Hour,
	}))

	api.RegisterRoutes(r, h)

	srv := &http.Server{
		Addr:         cfg.ListenAddr,
		Handler:      r,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 30 * time.Second,
	}

	go func() {
		log.Printf("olcrtc-manager listening on %s", cfg.ListenAddr)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("server error: %v", err)
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit
	log.Println("shutting down...")

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		log.Fatalf("server shutdown error: %v", err)
	}
	m.StopAll()
}
