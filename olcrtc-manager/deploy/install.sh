#!/usr/bin/env bash
# One-liner для установки olcrtc-manager на свежий VPS (Ubuntu/Debian):
#
#   curl -fsSL https://raw.githubusercontent.com/openlibrecommunity/olcrtc/master/install.sh | bash
#
# А этот скрипт ставит сам olcrtc-manager + systemd unit.
set -e

cd /tmp
apt-get update -qq
apt-get install -y -qq curl wget tar ca-certificates

ARCH=$(dpkg --print-architecture)
case "$ARCH" in
  amd64) BIN_ARCH="linux-amd64" ;;
  arm64) BIN_ARCH="linux-arm64" ;;
  *) echo "unsupported arch $ARCH"; exit 1 ;;
esac

# TODO: выкладывайте релизы в GitHub и подставляйте URL ниже. Сейчас — соберите руками через make cross
# wget https://github.com/you/olcrtc-manager/releases/latest/download/olcrtc-manager-${BIN_ARCH}.tar.gz
# tar xzf olcrtc-manager*.tar.gz

mkdir -p /var/lib/olcrtc-manager/instances
cp olcrtc-manager /usr/local/bin/olcrtc-manager
chmod +x /usr/local/bin/olcrtc-manager

cp deploy/olcrtc-manager.service /etc/systemd/system/olcrtc-manager.service
cp deploy/env.example /etc/default/olcrtc-manager

# Генерируем секретный токен, если не задан
if ! grep -q "^OLCRMGR_API_KEY=change-me" /etc/default/olcrtc-manager; then
  true
else
  sed -i "s|^OLCRMGR_API_KEY=.*|OLCRMGR_API_KEY=$(openssl rand -hex 32)|" /etc/default/olcrtc-manager
fi

systemctl daemon-reload
systemctl enable --now olcrtc-manager
echo "[ok] olcrtc-manager installed. Check: systemctl status olcrtc-manager"
echo "[ok] config: /etc/default/olcrtc-manager"
