// Package api implements the olcrtc-manager REST API (Gin handlers + routes).
package api

import (
	"errors"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/gin-gonic/gin"

	"olcrtc-manager/internal/manager"
)

// Handler holds the manager + api key for auth.
type Handler struct {
	mgr    *manager.Manager
	apiKey string
}

// NewHandler creates a new API handler.
func NewHandler(mgr *manager.Manager, apiKey string) *Handler {
	return &Handler{mgr: mgr, apiKey: apiKey}
}

// FIX #4: Simple in-memory rate limiter (token bucket, per-IP)
type rateLimiter struct {
	mu      sync.RWMutex
	clients map[string]*tokenBucket
	cleanup *time.Ticker
}

type tokenBucket struct {
	tokens     int
	lastRefill time.Time
}

var globalLimiter = &rateLimiter{
	clients: make(map[string]*tokenBucket),
	cleanup: time.NewTicker(5 * time.Minute),
}

func init() {
	go globalLimiter.cleanupLoop()
}

func (rl *rateLimiter) cleanupLoop() {
	for range rl.cleanup.C {
		rl.mu.Lock()
		for ip, bucket := range rl.clients {
			if time.Since(bucket.lastRefill) > 10*time.Minute {
				delete(rl.clients, ip)
			}
		}
		rl.mu.Unlock()
	}
}

func (rl *rateLimiter) allow(ip string, readLimit, burst int) bool {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	
	now := time.Now()
	bucket, exists := rl.clients[ip]
	if !exists {
		bucket = &tokenBucket{
			tokens:     burst,
			lastRefill: now,
		}
		rl.clients[ip] = bucket
		return true
	}
	
	// Refill tokens (1 per second for reads, slower for writes)
	elapsed := now.Sub(bucket.lastRefill)
	refillRate := float64(elapsed.Seconds())
	if refillRate > 0 {
		bucket.tokens += int(refillRate * float64(readLimit))
		if bucket.tokens > burst {
			bucket.tokens = burst
		}
	}
	bucket.lastRefill = now
	
	if bucket.tokens > 0 {
		bucket.tokens--
		return true
	}
	return false
}

// RegisterRoutes wires the routes into the gin engine with auth middleware.
func RegisterRoutes(r *gin.Engine, h *Handler) {
	api := r.Group("/api/v1")
	api.Use(h.authMiddleware())
	{
		// Apply rate limiting: READ=10 req/s burst=30, WRITE=3 req/s burst=10
		api.GET("/instances", h.rateLimitRead, h.listInstances)
		api.GET("/instances/:id", h.rateLimitRead, h.getInstance)
		api.GET("/user/:user_id", h.rateLimitRead, h.getUserInstance)
		api.GET("/user/:user_id/uri", h.rateLimitRead, h.getUserURI)
		api.GET("/user/:user_id/yaml", h.rateLimitRead, h.getUserYAML)
		api.GET("/user/:user_id/sub", h.rateLimitRead, h.getUserSub)
		
		api.POST("/instances", h.rateLimitWrite, h.createInstance)
		api.DELETE("/instances/:id", h.rateLimitWrite, h.stopInstance)
		api.DELETE("/user/:user_id", h.rateLimitWrite, h.stopUserInstance)
	}
	r.GET("/healthz", func(c *gin.Context) { c.JSON(http.StatusOK, gin.H{"status": "ok"}) })
}

func (h *Handler) rateLimitRead(c *gin.Context) {
	ip := c.ClientIP()
	if !globalLimiter.allow(ip, 10, 30) {
		c.JSON(http.StatusTooManyRequests, gin.H{"error": "rate limit exceeded"})
		c.Abort()
		return
	}
	c.Next()
}

func (h *Handler) rateLimitWrite(c *gin.Context) {
	ip := c.ClientIP()
	if !globalLimiter.allow(ip, 3, 10) {
		c.JSON(http.StatusTooManyRequests, gin.H{"error": "rate limit exceeded"})
		c.Abort()
		return
	}
	c.Next()
}

func (h *Handler) authMiddleware() gin.HandlerFunc {
	return func(c *gin.Context) {
		got := c.GetHeader("Authorization")
		want := "Bearer " + h.apiKey
		if !strings.EqualFold(got, want) {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
			c.Abort()
			return
		}
		c.Next()
	}
}

func (h *Handler) createInstance(c *gin.Context) {
	var req manager.InstanceRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	i, err := h.mgr.Create(req)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusCreated, i)
}

func (h *Handler) listInstances(c *gin.Context) {
	list, err := h.mgr.List()
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, list)
}

func (h *Handler) getInstance(c *gin.Context) {
	i, err := h.mgr.Get(c.Param("id"))
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if i == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "not found"})
		return
	}
	c.JSON(http.StatusOK, i)
}

func (h *Handler) stopInstance(c *gin.Context) {
	if err := h.mgr.Stop(c.Param("id")); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"status": "stopped"})
}

func parseUserID(s string) (int64, error) {
	id, err := strconv.ParseInt(s, 10, 64)
	if err != nil || id <= 0 {
		return 0, errors.New("invalid user id")
	}
	return id, nil
}

func (h *Handler) getUserInstance(c *gin.Context) {
	uid, err := parseUserID(c.Param("user_id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	i, err := h.mgr.GetByUser(uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if i == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "no instance for user"})
		return
	}
	c.JSON(http.StatusOK, gin.H{
		"instance": i,
		"uri":      manager.BuildUserURI(i, i.Comment),
		"yaml":     manager.BuildUserYAML(i),
	})
}

func (h *Handler) stopUserInstance(c *gin.Context) {
	uid, err := parseUserID(c.Param("user_id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	if err := h.mgr.StopByUser(uid); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"status": "stopped"})
}

func (h *Handler) getUserURI(c *gin.Context) {
	uid, err := parseUserID(c.Param("user_id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	i, err := h.mgr.GetByUser(uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if i == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "no instance for user"})
		return
	}
	mimo := c.DefaultQuery("mimo", i.Comment)
	c.JSON(http.StatusOK, gin.H{"uri": manager.BuildUserURI(i, mimo)})
}

func (h *Handler) getUserYAML(c *gin.Context) {
	uid, err := parseUserID(c.Param("user_id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	i, err := h.mgr.GetByUser(uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if i == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "no instance for user"})
		return
	}
	c.String(http.StatusOK, "application/yaml; charset=utf-8", manager.BuildUserYAML(i))
}

// subscription in olcrtc sub.md format (plain text)
func (h *Handler) getUserSub(c *gin.Context) {
	uid, err := parseUserID(c.Param("user_id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}
	i, err := h.mgr.GetByUser(uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if i == nil {
		c.String(http.StatusNotFound, "text/plain; charset=utf-8", "# no active subscription")
		return
	}
	now := c.GetInt64("__now")
	if now == 0 {
		now = 0
	}
	var usedGB string
	usedGB = "0mb/0gb"
	name := "OlcRTC Subscription"
	if i.Comment != "" {
		name = i.Comment
	}
	body := "#name: " + name + "\n"
	body += "#update: " + strconv.FormatInt(now, 10) + "\n"
	body += "#refresh: 10m\n"
	body += "#used: " + usedGB + "\n"
	body += "\n"
	body += manager.BuildUserURI(i, i.Comment) + "\n"
	body += "##name: " + providerLabel(i.Provider, i.Transport) + "\n"
	body += "##comment: provider=" + i.Provider + " transport=" + i.Transport + "\n"
	c.String(http.StatusOK, "text/plain; charset=utf-8", body)
}

func providerLabel(p, t string) string {
	return strings.ToUpper(p) + " / " + t
}
