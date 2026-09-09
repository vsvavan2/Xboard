// Package api implements the olcrtc-manager REST API (Gin handlers + routes).
package api

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

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

// RegisterRoutes wires the routes into the gin engine with auth middleware.
func RegisterRoutes(r *gin.Engine, h *Handler) {
	api := r.Group("/api/v1")
	api.Use(h.authMiddleware())
	{
		api.POST("/instances", h.createInstance)
		api.GET("/instances", h.listInstances)
		api.GET("/instances/:id", h.getInstance)
		api.DELETE("/instances/:id", h.stopInstance)
		api.GET("/user/:user_id", h.getUserInstance)
		api.DELETE("/user/:user_id", h.stopUserInstance)
		api.GET("/user/:user_id/uri", h.getUserURI)
		api.GET("/user/:user_id/yaml", h.getUserYAML)
		api.GET("/user/:user_id/sub", h.getUserSub)
	}
	r.GET("/healthz", func(c *gin.Context) { c.JSON(http.StatusOK, gin.H{"status": "ok"}) })
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
