#!/usr/bin/env bash
# =============================================================================
#  One-Click install: Xboard + OlcRTC Integration (vsvavan2/Xboard fork)
#  Устанавливает Docker + docker-compose, клонирует репозиторий и поднимает стек.
#  Запуск на свежем VPS (Ubuntu 22.04 / Debian 12):
#     curl -fsSL https://raw.githubusercontent.com/vsvavan2/Xboard/master/install.sh | bash
# =============================================================================
set -euo pipefail

REPO_URL="https://github.com/vsvavan2/Xboard"
BRANCH="${BRANCH:-master}"
INSTALL_DIR="${INSTALL_DIR:-/opt/xboard}"

log()  { echo -e "\033[1;32m[+]\033[0m $*"; }
warn() { echo -e "\033[1;33m[!]\033[0m $*"; }
err()  { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 0. Требования
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || err "Запустите от root (sudo -i)"
command -v apt-get >/dev/null 2>&1 || err "Поддерживаются только Debian/Ubuntu (apt-get)"

# ---------------------------------------------------------------------------
# 1. Установка Docker и плагинов
# ---------------------------------------------------------------------------
log "Обновляем apt и ставим зависимости..."
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg lsb-release openssl git

if ! command -v docker >/dev/null 2>&1; then
    log "Ставим Docker официальным скриптом..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
fi
if ! docker compose version >/dev/null 2>&1; then
    apt-get install -y -qq docker-compose-plugin
fi
log "Docker: $(docker -v), compose: $(docker compose version | head -1)"

# ---------------------------------------------------------------------------
# 2. Клонирование / обновление репозитория
# ---------------------------------------------------------------------------
if [ -d "${INSTALL_DIR}/.git" ]; then
    log "Обновляем существующий репозиторий в ${INSTALL_DIR}..."
    cd "${INSTALL_DIR}"
    git fetch --all --tags
    git reset --hard "origin/${BRANCH}"
else
    log "Клонируем ${REPO_URL} (${BRANCH}) → ${INSTALL_DIR}"
    rm -rf "${INSTALL_DIR}"
    git clone --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${INSTALL_DIR}"
    cd "${INSTALL_DIR}"
fi

# ---------------------------------------------------------------------------
# 3. Подготовка .env
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    log "Генерируем .env из .env.olcrtc.example"
    cp .env.olcrtc.example .env

    # Генерируем APP_KEY
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64 -w0)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env

    # Генерируем OLCRMGR_API_KEY
    API_KEY="$(openssl rand -hex 32)"
    sed -i "s|^OLCRMGR_API_KEY=.*|OLCRMGR_API_KEY=${API_KEY}|" .env

    # Автодетект домена/IP для APP_URL
    PUBLIC_IP=$(curl -fsSL --max-time 5 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
    sed -i "s|^APP_URL=.*|APP_URL=http://${PUBLIC_IP}:7001|" .env
    log "Сгенерирован .env — APP_URL=http://${PUBLIC_IP}:7001"
    warn "⚠️  Не забудьте поменять APP_URL на https://ваш.домен после настройки SSL"
else
    log ".env уже существует — оставляем как есть"
fi

# ---------------------------------------------------------------------------
# 4. Первичная установка Xboard (миграции, админ-пользователь)
# ---------------------------------------------------------------------------
log "Запускаем xboard:install (следуйте инструкциям в терминале)..."
mkdir -p .docker/.data storage/logs storage/theme plugins
chmod -R 777 .docker/.data storage plugins 2>/dev/null || true
docker compose pull || true
docker compose run -it --rm -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
    xboard php artisan xboard:install || \
    warn "xboard:install был прерван или упал — запустите вручную: cd ${INSTALL_DIR} && docker compose run -it --rm xboard php artisan xboard:install"

# ---------------------------------------------------------------------------
# 5. Запуск стека
# ---------------------------------------------------------------------------
log "Поднимаем Xboard + olcrtc-manager..."
docker compose up -d

log ""
log "======================================================================"
log " ✅ ГОТОВО! VPN-панель установлена в ${INSTALL_DIR}"
log "======================================================================"
log ""
log "🌐 Панель:           $(grep '^APP_URL=' .env | cut -d= -f2)"
log "📁 Директория:       ${INSTALL_DIR}"
log "🔑 OlcRTC API key:   $(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)"
log ""
log "📌 Что дальше (в админке http://IP:7001 под админом):"
log "   1. Плагины → OlcRTC Integration → Настроить:"
log "        URL:          http://olcrtc-manager:8080"
log "        API-ключ:     (значение из .env выше — уже подтянуто по env)"
log "        Provider:     jitsi (рекомендуется)"
log "        Transport:    datachannel"
log "        Trial:        6 часов, включить"
log "        → Включить плагин"
log ""
log "   2. Платёжки → Добавить → ЮKassa (YooKassa):"
log "        shop_id, secret_key — из личного кабинета ЮKassa"
log "        методы: bank_card,sberbank,yoomoney,sbp,tinkoff_bank"
log ""
log "   3. Тарифы → Создать план (месяц / квартал / год)"
log ""
log "📞 Если у вас уже есть бинарник olcrtc на хосте:"
log "        раскомментируйте volume с /usr/local/bin/olcrtc в docker-compose.yml"
log "======================================================================"
