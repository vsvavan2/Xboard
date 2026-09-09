// Package config loads olcrtc-manager runtime configuration.
package config

import (
	"os"
	"strings"
)

// Config is the olcrtc-manager runtime configuration.
type Config struct {
	ListenAddr     string
	APIKey         string
	DatabasePath   string
	OlcRTCBin      string
	InstancesDir   string
	DefaultDNS     string
	DefaultProvider string
	DefaultTransport string
	DefaultToken   string
}

// Load reads config from environment variables with sane defaults.
func Load() *Config {
	return &Config{
		ListenAddr:      getEnv("OLCRMGR_LISTEN", "127.0.0.1:8080"),
		APIKey:          getEnv("OLCRMGR_API_KEY", "change-me"),
		DatabasePath:    getEnv("OLCRMGR_DB", "/var/lib/olcrtc-manager/instances.db"),
		OlcRTCBin:       getEnv("OLCRMGR_BIN", "/usr/local/bin/olcrtc"),
		InstancesDir:    getEnv("OLCRMGR_INSTANCES_DIR", "/var/lib/olcrtc-manager/instances"),
		DefaultDNS:      getEnv("OLCRMGR_DEFAULT_DNS", "8.8.8.8:53"),
		DefaultProvider: getEnv("OLCRMGR_DEFAULT_PROVIDER", "jitsi"),
		DefaultTransport: getEnv("OLCRMGR_DEFAULT_TRANSPORT", "datachannel"),
		DefaultToken:    getEnv("OLCRMGR_DEFAULT_TOKEN", ""),
	}
}

func getEnv(k, def string) string {
	v := os.Getenv(k)
	if strings.TrimSpace(v) == "" {
		return def
	}
	return v
}
