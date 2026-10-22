# syntax=docker/dockerfile:1.6
# ====== Stage 1: build olcrtc-manager from source ======
FROM golang:1.27-alpine AS builder

RUN apk add --no-cache gcc musl-dev

WORKDIR /src
COPY olcrtc-manager/ ./
RUN go mod download
RUN go mod tidy
RUN CGO_ENABLED=1 GOOS=linux go build -trimpath -ldflags "-s -w" -o /out/olcrtc-manager ./

# ====== Stage 2: download olcrtc binary with fallback to build from source ======
FROM golang:1.27-alpine AS olcrtc-fetcher
WORKDIR /out
RUN apk add --no-cache curl tar ca-certificates git
# У openlibrecommunity/olcrtc НЕТ GitHub-релизов (только ветка master и тег v0.0.1),
# поэтому «latest»/«v2.0.0» дают 404 и сборка падает. Ставим master — тогда
# download 404-нётся и автоматически сработает fallback: git clone --branch master + go build.
ARG OLCRTC_RELEASE="master"
ARG TARGETARCH
RUN set -e; \
    ARCH=$(echo "${TARGETARCH}" | sed -e 's/amd64/linux-amd64/' -e 's/arm64/linux-arm64/'); \
    echo "Attempting to download olcrtc for ${ARCH} from openlibrecommunity/olcrtc release ${OLCRTC_RELEASE}"; \
    BASE_URL="https://github.com/openlibrecommunity/olcrtc/releases/download/${OLCRTC_RELEASE}"; \
    if curl -fsSL -o olcrtc.tar.gz "${BASE_URL}/olcrtc-${ARCH}.tar.gz"; then \
      tar xzf olcrtc.tar.gz && chmod +x olcrtc && echo "Downloaded olcrtc: $(./olcrtc -v 2>&1 || true)"; \
    else \
      echo "Download failed, building olcrtc from source..."; \
      cd /tmp && \
      git clone --depth 1 --branch ${OLCRTC_RELEASE} https://github.com/openlibrecommunity/olcrtc.git && \
      cd olcrtc && \
      go build -o /out/olcrtc ./cmd/olcrtc && \
      chmod +x /out/olcrtc && \
      echo "Built olcrtc from source: $(/out/olcrtc -v 2>&1 || true)"; \
    fi

# ====== Stage 3: runtime image ======
FROM alpine:3.20

RUN apk add --no-cache ca-certificates tzdata && \
    addgroup -g 10001 -S olcrmgr && \
    adduser  -u 10000 -S -G olcrmgr -h /var/lib/olcrtc-manager -s /sbin/nologin olcrmgr

COPY --from=builder     /out/olcrtc-manager  /usr/local/bin/olcrtc-manager
COPY --from=olcrtc-fetcher /out/olcrtc       /usr/local/bin/olcrtc

RUN mkdir -p /var/lib/olcrtc-manager/instances && \
    chown -R olcrmgr:olcrmgr /var/lib/olcrtc-manager

USER olcrmgr
WORKDIR /var/lib/olcrtc-manager

ENV OLCRMGR_LISTEN=0.0.0.0:8080 \
    OLCRMGR_DB=/var/lib/olcrtc-manager/instances.db \
    OLCRMGR_BIN=/usr/local/bin/olcrtc \
    OLCRMGR_INSTANCES_DIR=/var/lib/olcrtc-manager/instances \
    OLCRMGR_DEFAULT_PROVIDER=jitsi \
    OLCRMGR_DEFAULT_TRANSPORT=datachannel \
    OLCRMGR_DEFAULT_DNS=8.8.8.8:53 \
    OLCRMGR_JITSI_SERVER=https://meet.egovm.ru

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz | grep -q ok || exit 1

ENTRYPOINT ["/usr/local/bin/olcrtc-manager"]
