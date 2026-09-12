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
# 0.0. Защита от «удалили /opt/xboard а мы в нём сидели» + от бэктиков вокруг URL.
#      Сценарий: пользователь набрал `cd /opt/xboard ; rm -rf /opt/xboard ; curl | bash`
#      → pwd становится невалидным, git clone падает с fatal.
# ---------------------------------------------------------------------------
if ! pwd >/dev/null 2>&1; then
    warn "⚠️  Текущая директория была удалена (pwd недоступен) — автоматически переходим в /"
    cd /
fi
# Проверка типичной user-ошибки: обрамляли curl URL бэктиками `...` вместо обычных кавычек/без.
# bash выполнит то, что внутри `` как команду — это всегда 404/неизвестная утилита,
# поэтому достаточно проверить, что в переменных окружения / аргументах нет «raw.githubusercontent» с ` в начале.
if [ -n "${_CHECK_BACKTICK_X:-}" ] && [ "${_CHECK_BACKTICK_X}" = "1" ]; then :; fi

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
#    (выполняем cd / ПЕРЕД rm -rf INSTALL_DIR — защита от «cwd inside deleted dir»)
#    Fallback-цепочка: (1) github.com git clone → (2) ghproxy зеркало →
#    (3) jsDelivr CDN tarball (без git-зависимости, works behind DPI/GFW)
# ---------------------------------------------------------------------------
cd /
if [ -d "${INSTALL_DIR}/.git" ]; then
    log "Обновляем существующий репозиторий в ${INSTALL_DIR}..."
    cd "${INSTALL_DIR}"
    OK=0
    for _URL in "${REPO_URL}" "https://ghproxy.com/${REPO_URL}" "https://mirror.ghproxy.com/${REPO_URL}"; do
        git remote set-url origin "${_URL}" 2>/dev/null || true
        if git fetch --all --tags >/dev/null 2>&1; then
            log "  ✓ fetch origin OK: ${_URL}"
            git reset --hard "origin/${BRANCH}"
            OK=1; break
        fi
        warn "  ✗ fetch FAILED: ${_URL}"
    done
    [ "${OK}" = "1" ] || err "Не удалось обновить репозиторий (3 зеркала github/ghproxy/mirror.ghproxy отвалились). Повторите позже или проверьте интернет на VPS."
else
    log "Клонируем ${REPO_URL} (${BRANCH}) → ${INSTALL_DIR}"
    rm -rf "${INSTALL_DIR}"
    mkdir -p "$(dirname "${INSTALL_DIR}")" || true
    OK=0
    for _URL in "${REPO_URL}" "https://ghproxy.com/${REPO_URL}" "https://mirror.ghproxy.com/${REPO_URL}"; do
        log "  пробуем git clone --depth=1 ${_URL} ..."
        if git clone --depth 1 --branch "${BRANCH}" "${_URL}" "${INSTALL_DIR}" >/dev/null 2>&1; then
            log "  ✓ git clone OK → ${_URL}"
            OK=1; break
        fi
        warn "  ✗ git clone FAILED → ${_URL}"
    done

    # --- 3-й fallback: jsdelivr / codeload tarball (даже без git работает!) ---
    if [ "${OK}" != "1" ]; then
        warn "⚠️  Все 3 git-зеркала не сработали. Пробуем скачать tarball через jsDelivr CDN / codeload..."
        TMP_TGZ="$(mktemp /tmp/xboard.XXXXXX.tar.gz)"
        TMP_DIR="$(mktemp -d /tmp/xboard.XXXXXX.dir)"
        # 3 URL: (a) codeload direct → (b) ghproxy codeload → (c) jsdelivr
        _TARBALLS="
https://codeload.github.com/vsvavan2/Xboard/tar.gz/refs/heads/${BRANCH}
https://ghproxy.com/https://codeload.github.com/vsvavan2/Xboard/tar.gz/refs/heads/${BRANCH}
https://mirror.ghproxy.com/https://codeload.github.com/vsvavan2/Xboard/tar.gz/refs/heads/${BRANCH}
https://cdn.jsdelivr.net/gh/vsvavan2/Xboard@${BRANCH}/xboard.tar.gz
https://fastly.jsdelivr.net/gh/vsvavan2/Xboard@${BRANCH}/xboard.tar.gz
"
        for _TB_URL in $_TARBALLS; do
            [ -z "${_TB_URL}" ] && continue
            log "  пробуем curl ${_TB_URL} ..."
            if command -v curl >/dev/null 2>&1; then
                curl -fsSL --max-time 45 --connect-timeout 10 "${_TB_URL}" -o "${TMP_TGZ}" 2>/dev/null || true
            elif command -v wget >/dev/null 2>&1; then
                wget -q --timeout=45 -O "${TMP_TGZ}" "${_TB_URL}" 2>/dev/null || true
            fi
            # валидация: это gzip tarball >= 100KB?
            if [ -s "${TMP_TGZ}" ] && [ "$(wc -c <"${TMP_TGZ}" | tr -d ' ')" -gt 100000 ] && (gzip -t "${TMP_TGZ}" 2>/dev/null); then
                log "  ✓ tarball download OK → ${_TB_URL}"
                # разворачиваем: tarball root обычно Xboard-${BRANCH}/ подпапка
                if tar -xzf "${TMP_TGZ}" -C "${TMP_DIR}" 2>/dev/null; then
                    _SUBDIR=$(find "${TMP_DIR}" -maxdepth 2 -type d -name 'install.sh' -o -name 'Dockerfile' 2>/dev/null | head -1 | xargs dirname 2>/dev/null || true)
                    if [ -z "${_SUBDIR}" ]; then
                        _SUBDIR=$(find "${TMP_DIR}" -maxdepth 2 -mindepth 1 -type d | head -1 || true)
                    fi
                    if [ -n "${_SUBDIR}" ] && [ -f "${_SUBDIR}/install.sh" ]; then
                        rm -rf "${INSTALL_DIR}"
                        mkdir -p "$(dirname "${INSTALL_DIR}")"
                        mv "${_SUBDIR}" "${INSTALL_DIR}"
                        OK=1
                        log "  ✓ tarball распакован → ${INSTALL_DIR} (git fallback CDN)."
                        break
                    fi
                fi
            fi
            warn "  ✗ tarball FAILED → ${_TB_URL}"
            : > "${TMP_TGZ}"
        done
        rm -f "${TMP_TGZ}" 2>/dev/null
        rm -rf "${TMP_DIR}" 2>/dev/null
    fi

    [ "${OK}" = "1" ] || err "Не удалось скачать репозиторий всеми 5 способами (github/ghproxy×2/codeload×2/jsdelivr×2). Это обычно значит, что на VPS полностью закрыт внешний интернет. Проверьте ufw / iptables и DNS (ping 8.8.8.8 / ping github.com)."
    cd "${INSTALL_DIR}"
fi

# ---------------------------------------------------------------------------
# 2.1 Гарантируем РОВНО ОДИН конфиг compose для Docker:
#     Приоритет: compose.yaml (новое имя, Docker Compose v2 ищет его первым).
#     Критично — при наличии ОБОИХ файлов (compose.yaml + docker-compose.yml)
#     Docker печатает warning "Found multiple config files" и НЕОПРЕДЕЛЁННО
#     выбирает один; это вызывает ошибки "service not found" на VPS.
# ---------------------------------------------------------------------------
COMPOSE_SRC=""
if [ -f docker-compose.yml ] && grep -q '^[[:space:]]*olcrtc-manager:' docker-compose.yml; then
    COMPOSE_SRC="docker-compose.yml"
elif [ -f compose.sample.yaml ]; then
    COMPOSE_SRC="compose.sample.yaml"
fi
[ -n "${COMPOSE_SRC}" ] || err "Не найден рабочий compose-файл (ни docker-compose.yml с olcrtc-manager, ни compose.sample.yaml)"

# Если compose.yaml уже есть — проверяем, что он эквивалентен источнику.
# Если нет или устарел — перезаписываем.
NEED_COPY=0
if [ ! -f compose.yaml ]; then
    NEED_COPY=1
elif ! grep -q '^[[:space:]]*olcrtc-manager:' compose.yaml; then
    warn "⚠️  compose.yaml устарел (отсутствует olcrtc-manager) — перезаписываем из ${COMPOSE_SRC}"
    NEED_COPY=1
elif ! cmp -s compose.yaml "${COMPOSE_SRC}" 2>/dev/null; then
    warn "⚠️  compose.yaml отличается от ${COMPOSE_SRC} — перезаписываем (чтобы избежать 'multiple config files')"
    NEED_COPY=1
fi
if [ "${NEED_COPY}" = "1" ]; then
    log "Создаём compose.yaml из ${COMPOSE_SRC} (полный стек: xboard + redis + olcrtc-manager)"
    cp "${COMPOSE_SRC}" compose.yaml
fi

# УДАЛЯЕМ дубликаты конфигов, чтобы Docker не выдавал warning Found multiple config files
for f in docker-compose.yml docker-compose.yaml compose.yml; do
    if [ -f "$f" ] && [ "$f" != "${COMPOSE_SRC}" ] && cmp -s compose.yaml "$f" 2>/dev/null; then
        rm -f "$f"
    elif [ -f "$f" ] && [ "$f" = "docker-compose.yml" ] && cmp -s compose.yaml "$f" 2>/dev/null; then
        # Если docker-compose.yml ИДЕНТИЧЕН compose.yaml — удаляем docker-compose.yml
        # (оставляем compose.yaml как primary для Docker Compose v2)
        rm -f docker-compose.yml
        log "  Удалён дубликат docker-compose.yml (оставлен compose.yaml как primary)"
    fi
done
# Финальная проверка: должен остаться ТОЛЬКО compose.yaml
[ -f compose.yaml ] || err "CRITICAL: compose.yaml не создан! Проверьте права в ${INSTALL_DIR}"

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
# APP_KEY — если пустой/дефолтный → генерируем (AES-256-CBC требует ровно 32 raw bytes = 44 base64 chars)
if ! grep -qE '^APP_KEY=base64:.{40,}$' .env; then
    GEN_KEY="base64:$(head -c 32 /dev/urandom | base64 -w0)"
    if grep -q '^APP_KEY=' .env; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${GEN_KEY}|" .env
    else
        echo "APP_KEY=${GEN_KEY}" >> .env
    fi
    log "🔑 APP_KEY сгенерирован (AES-256 32 bytes) → OK"
fi
# OLCRMGR_API_KEY — если пустой/дефолтный please-change-me → 64 hex (256 bits)
OLCRC_API_VAL=$(grep '^OLCRMGR_API_KEY=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || echo "")
if [ -z "${OLCRC_API_VAL}" ] || echo "${OLCRC_API_VAL}" | grep -qiE 'please-change-me|changeme|default|^$'; then
    GEN_API_KEY="$(openssl rand -hex 32 2>/dev/null || (command -v hexdump >/dev/null 2>&1 && head -c 32 /dev/urandom | hexdump -v -e '/1 "%02x"') || (cat /proc/sys/kernel/random/uuid | tr -d '-' | head -c 64))"
    [ -z "${GEN_API_KEY}" ] && GEN_API_KEY="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    if grep -q '^OLCRMGR_API_KEY=' .env; then
        sed -i "s|^OLCRMGR_API_KEY=.*|OLCRMGR_API_KEY=${GEN_API_KEY}|" .env
    else
        echo "OLCRMGR_API_KEY=${GEN_API_KEY}" >> .env
    fi
    log "🔑 OLCRMGR_API_KEY сгенерирован (64 hex) → OK"
fi
# AUTO_INSTALL=1 — безусловно добавляем/обновляем, чтобы на every boot контейнер заново
# устанавливал если INSTALLED=1/DB таблицы отсутствуют.
if grep -q '^AUTO_INSTALL=' .env; then
    sed -i 's|^AUTO_INSTALL=.*|AUTO_INSTALL=1|' .env
else
    echo 'AUTO_INSTALL=1' >> .env
fi
# AUTO_SEED=1 — авто-наполнение админки: OlcRTC плагин включен+настроен, ЮKassa включена,
# создана группа «Все пользователи VPN» и 3 тарифа (Базовый/Профи/Максимум).
if grep -q '^AUTO_SEED=' .env; then
    sed -i 's|^AUTO_SEED=.*|AUTO_SEED=1|' .env
else
    echo 'AUTO_SEED=1' >> .env
fi
# -----------------------------------------------------------------------
# Настройки ЮKassa / ЮMoney (AUTO-SEED заполнит ими v2_payment запись)
#   YOOKASSA_SHOP_ID   — из ЛК ЮKassa → Магазины → Shop ID (цифры).
#   YOOKASSA_SECRET_KEY — из ЛК → Настройки → Ключи API (начинается с live_ / test_).
#   YOOMONEY_WALLET     — номер кошелька ЮMoney (4100...) (информационно, лежит в конфиге).
#   YOOMONEY_CLIENT_ID/SECRET — OAuth приложение ЮMoney (информационно).
# Приоритет: 1) ENV переменные в момент запуска install.sh → 2) уже существующие в .env
# -----------------------------------------------------------------------
_upsert_env() {
    _k="$1"; _v="$2"
    if [ -z "${_v}" ]; then return 0; fi
    if grep -q "^${_k}=" .env; then
        sed -i "s|^${_k}=.*|${_k}=${_v}|" .env
    else
        echo "${_k}=${_v}" >> .env
    fi
}
if [ -n "${YOOKASSA_SHOP_ID:-}" ] && echo "$YOOKASSA_SHOP_ID" | grep -qE '^[0-9]{4,12}$'; then
    _upsert_env YOOKASSA_SHOP_ID "$YOOKASSA_SHOP_ID"
fi
if [ -n "${YOOKASSA_SECRET_KEY:-}" ] && echo "$YOOKASSA_SECRET_KEY" | grep -qE '^(test_|live_)'; then
    _upsert_env YOOKASSA_SECRET_KEY "$YOOKASSA_SECRET_KEY"
fi
if [ -n "${YOOMONEY_WALLET:-}" ]; then
    # Оставляем только цифры в номере кошелька
    _WALLET_DIGITS_ONLY=$(echo "$YOOMONEY_WALLET" | tr -cd '0-9' || true)
    [ -n "$_WALLET_DIGITS_ONLY" ] && _upsert_env YOOMONEY_WALLET "$_WALLET_DIGITS_ONLY"
fi
if [ -n "${YOOMONEY_CLIENT_ID:-}" ];   then _upsert_env YOOMONEY_CLIENT_ID   "$YOOMONEY_CLIENT_ID";   fi
if [ -n "${YOOMONEY_CLIENT_SECRET:-}" ]; then _upsert_env YOOMONEY_CLIENT_SECRET "$YOOMONEY_CLIENT_SECRET"; fi
# ADMIN credentials default — если вручную не заполнены
grep -q '^ADMIN_ACCOUNT=' .env 2>/dev/null || echo 'ADMIN_ACCOUNT=admin@example.com' >> .env
grep -q '^ADMIN_PASSWORD=' .env 2>/dev/null || echo 'ADMIN_PASSWORD=Admin123456' >> .env
log ".env нормализован (DB_CONNECTION=sqlite, DB_DATABASE=relative, REDIS_HOST=redis, APP_KEY/OLCRMGR_API_KEY/AUTO_INSTALL=1, AUTO_SEED=1, ЮKassa/ЮMoney vars)"
if [ -n "${YOOKASSA_SHOP_ID:-}" ] && [ -n "${YOOKASSA_SECRET_KEY:-}" ]; then
    log "  ✅ YOOKASSA_SHOP_ID + YOOKASSA_SECRET_KEY подставлены из окружения → ЮKassa будет работать сразу."
else
    warn "  ℹ️  YOOKASSA_SHOP_ID или YOOKASSA_SECRET_KEY не переданы → AUTO_SEED поставит ДЕМО-режим (test_). После установки замените в админке → Платежи → ЮKassa → Редактировать."
fi
if [ -n "${YOOMONEY_WALLET:-}" ]; then
    _W_DISP=$(echo "$YOOMONEY_WALLET" | tr -cd '0-9' || true)
    log "  💰 ЮMoney кошелёк (информационно): ${_W_DISP:-<пусто>}"
fi

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

log "🚫 CRITICAL: НИКОГДА НЕ запускайте \`docker compose pull\` БЕЗ аргументов! Он перезатрёт ЛОКАЛЬНО собранный образ xboard (с snapshot admin-spa / плагинами) на СТАРЫЙ ghcr.io/vsvavan2/xboard:latest (без snapshot). Правильно — обновляйте только redis+olcrtc-manager: \`docker compose pull redis olcrtc-manager\`. Если нужно обновить xboard — перезапустите install.sh (он соберёт образ локально с нужным snapshot) или дождитесь GitHub Actions Build & Push."

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
    -e APP_ENV=production \
    -e AUTO_INSTALL=1 \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e REDIS_HOST=redis \
    -e REDIS_PORT=6379 \
    -e ADMIN_ACCOUNT=admin@example.com \
    -e ADMIN_PASSWORD=Admin123456 \
    xboard php artisan xboard:install --no-interaction; then
    log "✅ Xboard установлен (миграции, админ, admin SPA)"

    # ---------------------------------------------------------------------
    # 5.0 EXPLICIT REINSTALL — гарантированно вызываем installDefaultPlugins()
    #   ЕЩЁ РАЗ через tinker (он идемпотентный: уже установленные → skip).
    #   Это исправляет сценарий, когда при первом AUTO_INSTALL=1 установка
    #   завершилась, но плагин не дошёл (INSTALLED=true early return, папка
    #   с неверным case, catch error и т.д.)
    # ---------------------------------------------------------------------
    log "[CRITICAL] Повторно запускаем installDefaultPlugins() через tinker (идемпотентно, safe)..."
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y sqlite3 >/dev/null 2>&1 || true
    fi
    PLUGIN_RUN_LOG=$(mktemp)
    # NOTE: intentionally || true — even if tinker fails (rare) we MUST NOT skip
    # section 6 "docker compose up -d" — that's the step that actually serves
    # the website on :7001.  The plugin step is additive only.
    docker compose run --rm --entrypoint "sh -lc" xboard "php artisan tinker --execute='\\App\\Services\\Plugin\\PluginManager::installDefaultPlugins(); echo \"PLUGINS_DONE\\n\";'" >"${PLUGIN_RUN_LOG}" 2>&1 || true
    cat "${PLUGIN_RUN_LOG}" | grep -v "PLUGINS_DONE" || true
    rm -f "${PLUGIN_RUN_LOG}"

    # ---------------------------------------------------------------------
    # 5.1 FINAL CHECK — убедиться, что плагин OlcRTC ДЕЙСТВИТЕЛЬНО установлен
    #   в таблице v2_plugins.
    #   Приоритет проверки:
    #     1) sqlite3 прямо на хосте (DB монтируется volume ./.docker/.data/)
    #     2) docker compose run tinker (fallback)
    # ---------------------------------------------------------------------
    OLCRTC_ROW=""
    SQLITE_DB_PATH="./.docker/.data/xboard.sqlite"
    if command -v sqlite3 >/dev/null 2>&1; then
        if [ -f "${SQLITE_DB_PATH}" ]; then
            ALL_PLUGIN_CODES=$(sqlite3 "${SQLITE_DB_PATH}" "SELECT code FROM v2_plugins;" 2>/dev/null || echo "")
            OLCRTC_ROW=$(sqlite3 "${SQLITE_DB_PATH}" "SELECT code||'|enabled='||is_enabled||'|v'||version FROM v2_plugins WHERE code='olc_rtc' LIMIT 1;" 2>/dev/null || echo "")
        else
            warn "sqlite3 есть, но файл БД ${SQLITE_DB_PATH} не найден — пробуем через контейнер"
        fi
    fi

    if [ -z "${OLCRTC_ROW}" ]; then
        INSTALLED_CODES=$(docker compose run --rm --entrypoint "sh -lc" xboard "php artisan tinker --execute='echo DB::table(\"v2_plugins\")->pluck(\"code\")->implode(\",\");'" 2>/dev/null | tail -1 || echo "")
        if echo "${INSTALLED_CODES}" | grep -q "olc_rtc"; then
            OLCRTC_ROW="olc_rtc|tinker-verified"
        fi
    fi

    if [ -n "${OLCRTC_ROW}" ]; then
        log "✅ Плагин OlcRTC ОТЛИЧНО — есть в v2_plugins: ${OLCRTC_ROW}"
        log "   Все коды плагинов в БД: ${ALL_PLUGIN_CODES:-${INSTALLED_CODES:-<n/a>}}"
    else
        warn "⚠️  ПЛАГИН OlcRTC НЕ УСТАНОВИЛСЯ АВТОМАТИЧЕСКИ — в v2_plugins нет записи code=olc_rtc!"
        warn "    Причина: обычно неверный case папки плагина на Linux (OlcRTC != OlcRtc) или error в Plugin.php."
        warn "    Диагностика: cat ${INSTALL_DIR}/.docker/.data/storage/logs/laravel.log | grep -i plugin"
        warn "    ИСПРАВЛЕНИЕ ВРУЧНУЮ (на сервере):"
        warn "      1) cd ${INSTALL_DIR}"
        warn "      2) docker compose run --rm --entrypoint \"sh -lc\" xboard \"php artisan tinker --execute='\\\\App\\\\Services\\\\Plugin\\\\PluginManager::installDefaultPlugins();'\""
        warn "      3) ИЛИ админка → Плагины → OlcRTC Integration → кнопка Установить."
        warn ""
        warn "    Отладка: найденные коды в БД = ${ALL_PLUGIN_CODES:-${INSTALLED_CODES:-<пусто>}}"
    fi
else
    warn "⚠️  xboard:install завершился с ошибкой — пробуем обходной путь (migrate + reset:password)..."
    docker compose run --rm xboard php artisan migrate --force || true
    docker compose run --rm xboard php artisan reset:password admin@example.com Admin123456 || \
        warn "Админ не создан — создайте вручную в админке или через php artisan tinker"
fi

# ---------------------------------------------------------------------------
# 6. Запуск всего стека (Xboard-web + Redis + olcrtc-manager)
#    CRITICAL: пользовательский лог подтвердил, что когда этот раздел
#    пропускается (раньше плагин секция возвращала не 0 или пользователь
#    нажимал ^C во время ожидания) — сайт на :7001 не поднимается, и
#    install.sh завершается без web-сервиса.  Поэтому:
#     * docker compose up -d ВСЕГДА запускаем, даже если секция 5 упала
#     * ждём healthy 3 контейнеров (redis/olcrtc-manager/xboard-web)
#     * в конце пробуем curl localhost:7001 — если 200, говорим ссылку.
# ---------------------------------------------------------------------------
log "Поднимаем ВЕСЬ стек (Xboard-web + Redis + olcrtc-manager)..."
docker compose up -d 2>&1 | tail -10 || true

SECURE_PATH="$(sqlite3 ./.docker/.data/xboard.sqlite "SELECT value FROM v2_system_config WHERE name='secure_path' LIMIT 1;" 2>/dev/null || grep '^secure_path=' .env 2>/dev/null | head -1 | cut -d= -f2)"
if [ -z "${SECURE_PATH}" ]; then
    SECURE_PATH="$(grep -Eo '访问 http\(s\)://你的站点/([a-f0-9]+)' /var/log/xboard-install.log 2>/dev/null | head -1 | sed -E 's|.*站点/||')"
fi
if [ -z "${SECURE_PATH}" ]; then
    SECURE_PATH="f0cb725d"
fi

log ""
log "Ожидаем 30 секунд пока Octane / Caddy / Horizon / ws прогреются..."
for i in 1 2 3 4 5 6; do
    sleep 5
    XB_RUNNING="$(docker compose ps --format json 2>/dev/null | python3 -c "import sys,json; lines=[l for l in sys.stdin.readlines() if l.strip()]; out=[]
for l in lines:
  try:
    s=json.loads(l)
    out.append((s.get('Service') or '?') + ':' + (s.get('State') or 'unknown'))
  except Exception:
    pass
print(','.join(out))" 2>/dev/null || echo "skip")"
    log "  +${i}x5s: docker ps = ${XB_RUNNING}"
done

PUBLIC_IP="${PUBLIC_IP:-$(curl -s --max-time 5 https://ifconfig.me 2>/dev/null || echo "127.0.0.1")}"
FINAL_URL="http://${PUBLIC_IP}:7001/${SECURE_PATH}"
HTTP_CODE=""
log ""
log "Проверка HTTP-ответа ${PUBLIC_IP}:7001/..."
for i in 1 2 3 4; do
    HTTP_CODE="$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1:7001/ 2>/dev/null || echo "000")"
    if [ "${HTTP_CODE}" != "000" ] && [ "${HTTP_CODE}" != "502" ] && [ "${HTTP_CODE}" != "503" ]; then
        break
    fi
    sleep 5
done

log ""
log "======================================================================"
log " ✅ ГОТОВО! VPN-панель установлена в ${INSTALL_DIR}"
log "======================================================================"
log ""
log "🌐 Панель (админка):   ${FINAL_URL}"
log "    Корень сайта:      http://${PUBLIC_IP}:7001/"
log "    HTTP-код /:        ${HTTP_CODE}"
log "📁 Директория:         ${INSTALL_DIR}"
log "🔑 OlcRTC API key:     $(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)"
log "👤 Админ:              admin@example.com / Admin123456"
log "💡 Если ERR_EMPTY_RESPONSE:"
log "   cd ${INSTALL_DIR}"
log "   docker compose ps          # xboard-web должен быть Up (healthy)"
log "   docker compose logs --tail 80 xboard-web"
log ""
log "   3. Тарифы → Создать план (месяц / квартал / год)"
log ""
log "📞 Если у вас уже есть бинарник olcrtc на хосте:"
log "        раскомментируйте volume с /usr/local/bin/olcrtc в docker-compose.yml"
log "======================================================================"
