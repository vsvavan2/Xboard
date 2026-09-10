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
# 3.1 Гарантируем важные параметры в .env (даже если .env уже был старый)
# ---------------------------------------------------------------------------
# Redis — обязательно redis (имя сервиса compose), а не 127.0.0.1
sed -i 's|^REDIS_HOST=.*|REDIS_HOST=redis|' .env
# SQLite DB — относительный путь или абсолютный ОДИН раз, config/database.php умный
sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' .env
sed -i 's|^DB_DATABASE=.*|DB_DATABASE=.docker/.data/xboard.sqlite|' .env
# Xboard порт на хосте
grep -q '^XBOARD_PORT=' .env || echo 'XBOARD_PORT=7001' >> .env
log ".env нормализован (DB_CONNECTION=sqlite, DB_DATABASE=relative, REDIS_HOST=redis)"

# ---------------------------------------------------------------------------
# 3.2 Папки + права + пустой SQLite файл (иначе драйвер может не создать сам)
# ---------------------------------------------------------------------------
mkdir -p .docker/.data storage/logs storage/theme plugins
chmod -R 777 .docker/.data storage plugins 2>/dev/null || true
# Создаём пустой sqlite файл (драйвер PDO не создаёт в некоторых режимах)
if [ ! -f .docker/.data/xboard.sqlite ]; then
    touch .docker/.data/xboard.sqlite
    chmod 777 .docker/.data/xboard.sqlite
    log "Создан пустой SQLite файл .docker/.data/xboard.sqlite"
fi

# ---------------------------------------------------------------------------
# 3.3 ЛОКАЛЬНАЯ ПЕРЕСБОРКА ОБРАЗОВ (САМЫЙ ВАЖНЫЙ ШАГ!)
#   Без этого Docker продолжит использовать СТАРЫЙ образ ghcr.io, который
#   собран ДО фиксов database.php / XboardInstall.php.
#   Пересобираем ЛОКАЛЬНО из свежезагруженного кода репозитория.
# ---------------------------------------------------------------------------
log "[CRITICAL] Пересобираем образ olcrtc-manager локально (берет код из origin/${BRANCH})..."
docker compose build olcrtc-manager 2>&1 | tail -10

log "[CRITICAL] Пересобираем образ xboard локально — это 3-8 минут, подождите..."
docker compose build xboard 2>&1 | tail -15

# ---------------------------------------------------------------------------
# 4. Сначала поднимаем БАЗОВЫЙ стек (redis/manager) чтобы они были готовы к install
# ---------------------------------------------------------------------------
log "Pull-им Docker образы (redis / olcrtc-manager)... Если ghcr.io denied — build будет локально..."
PULL_OUT=$(docker compose pull redis olcrtc-manager 2>&1 | tail -10) || true
echo "$PULL_OUT"
if echo "$PULL_OUT" | grep -qi "denied\|unauthorized\|403\|forbidden"; then
    warn "⚠️  GHCR образы Xboard / Xboard-olcrtc-manager ПРИВАТНЫЕ (denied / unauthorized)."
    warn "    Это ОК — сейчас будет ЛОКАЛЬНАЯ сборка из исходников (3-10 минут, в зависимости от CPU)."
    warn "    Чтобы ускорить установки в будущем — сделайте пакеты PUBLIC на GitHub (шаги):"
    warn "      1) Откройте https://github.com/users/vsvavan2/packages?repo_name=Xboard"
    warn "      2) Зайдите в каждый пакет → Package settings → Change visibility → Public"
    warn "      3) Сохраните. После этого pull-образы будут быстрые с CDN GHCR."
fi

log "Стартуем redis + olcrtc-manager — ждём healthy перед xboard:install..."
docker compose up -d redis olcrtc-manager 2>&1 | tail -5

# Wait up to 40s for redis healthy
for i in $(seq 1 20); do
    R_H=$(docker compose ps redis --format '{{.Status}}' 2>/dev/null || echo "")
    if echo "$R_H" | grep -q healthy; then
        log "Redis healthy ✓"
        break
    fi
    sleep 2
done
# olcrtc-manager healthy check
for i in $(seq 1 20); do
    O_H=$(docker compose ps olcrtc-manager --format '{{.Status}}' 2>/dev/null || echo "")
    if echo "$O_H" | grep -q healthy; then
        log "olcrtc-manager healthy ✓"
        break
    fi
    sleep 2
done

# ---------------------------------------------------------------------------
# 5. Первичная установка Xboard (миграции + админ + плагины + admin-SPA)
#    ВСЕ в одном вызове через AUTO_INSTALL — xboard:install сам делает migrate,
#    регистрирует админа, ставит default-плагины и клонирует admin SPA.
# ---------------------------------------------------------------------------
log "Запускаем БЕСКОНТАКТНУЮ установку Xboard (AUTO_INSTALL=1, headless)..."
if docker compose run --rm \
    -e AUTO_INSTALL=1 \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e REDIS_HOST=redis \
    -e REDIS_PORT=6379 \
    -e ADMIN_ACCOUNT=admin@example.com \
    -e ADMIN_PASSWORD=Admin123456 \
    xboard php artisan xboard:install; then
    log "✅ Xboard установлен (миграции, админ, admin SPA)"

    # ---------------------------------------------------------------------
    # 5.1 FINAL CHECK — убедиться, что плагин OlcRTC ДЕЙСТВИТЕЛЬНО установлен
    #   в таблице v2_plugins. Headless install раньше мог сказать "OK",
    #   а плагин не установился (error был только в storage/logs).
    # ---------------------------------------------------------------------
    INSTALLED_CODES=$(docker compose run --rm xboard php artisan tinker --execute="echo DB::table('v2_plugins')->pluck('code')->implode(',');") 2>/dev/null || echo ""
    if echo "$INSTALLED_CODES" | grep -q "olc_rtc"; then
        log "✅ Плагин OlcRTC (code=olc_rtc) ОТЛИЧНО — автоустановлен в v2_plugins"
    else
        # Fallback: try sqlite3 directly on host (often available)
        if command -v sqlite3 >/dev/null 2>&1; then
            OLCRTC_ROW=$(sqlite3 .docker/.data/xboard.sqlite "SELECT code,is_enabled,version FROM v2_plugins WHERE code='olc_rtc';" 2>/dev/null || echo "")
            if [ -n "$OLCRTC_ROW" ]; then
                log "✅ Плагин OlcRTC найден в БД напрямую (sqlite3): $OLCRTC_ROW"
            else
                warn "⚠️  ПЛАГИН OlcRTC НЕ УСТАНОВИЛСЯ АВТОМАТИЧЕСКИ — в v2_plugins нет записи code=olc_rtc!"
                warn "    Причина обычно: Plugin config file not found (ошибка в storage/logs/laravel.log)."
                warn "    ИСПРАВЛЕНИЕ (2 способа):"
                warn "      1) Перезапустите установщик плагинов вручную: "
                warn "         docker compose run --rm xboard php artisan tinker --execute=\"\\\App\\\Services\\\Plugin\\\PluginManager::installDefaultPlugins();\""
                warn "      2) ИЛИ зайдите в админку → Плагины → OlcRTC Integration → нажмите Установить."
            fi
        else
            warn "⚠️  ПЛАГИН OlcRTC — НЕВОЗМОЖНО проверить наличие (sqlite3 / tinker not reachable). Лучше проверить вручную в админке → Плагины."
        fi
    fi
else
    warn "⚠️  xboard:install завершился с ошибкой — пробуем обходной путь (migrate + reset:password)..."
    docker compose run --rm xboard php artisan migrate --force || true
    docker compose run --rm xboard php artisan reset:password admin@example.com Admin123456 || \
        warn "Админ не создан — создайте вручную в админке или через php artisan tinker"
fi

# ---------------------------------------------------------------------------
# 6. Запуск всего стека
# ---------------------------------------------------------------------------
log "Поднимаем ВЕСЬ стек (Xboard + Redis + olcrtc-manager)..."
docker compose up -d 2>&1 | tail -5

log ""
log "Ожидаем 20 секунд пока Octane/Caddy/Horizon прогреются..."
sleep 20

log ""
log "======================================================================"
log " ✅ ГОТОВО! VPN-панель установлена в ${INSTALL_DIR}"
log "======================================================================"
log ""
log "🌐 Панель:           $(grep '^APP_URL=' .env | cut -d= -f2)"
log "📁 Директория:       ${INSTALL_DIR}"
log "🔑 OlcRTC API key:   $(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)"
log "👤 Админ:            admin@example.com / Admin123456"
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
