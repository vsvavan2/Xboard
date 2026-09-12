#!/bin/sh
set -e

# Resolve the binding scheme based on whether the embedded Caddy is enabled.
#
# When ENABLE_CADDY=true (default), Caddy owns the public port (7001) and
# dispatches traffic internally; Octane and ws-server bind to localhost only
# so they cannot be reached from outside the container.
#
# When ENABLE_CADDY=false (e.g. an external reverse proxy or split mode),
# Octane takes the public port directly to keep behaviour identical to the
# pre-Caddy releases.
if [ "${ENABLE_CADDY}" = "true" ]; then
    : "${OCTANE_HOST:=127.0.0.1}"
    : "${OCTANE_PORT:=7002}"
    : "${WS_HOST:=127.0.0.1}"
    : "${WS_PORT:=8076}"
    : "${CADDY_LISTEN_PORT:=7001}"
else
    : "${OCTANE_HOST:=0.0.0.0}"
    : "${OCTANE_PORT:=7001}"
    : "${WS_HOST:=0.0.0.0}"
    : "${WS_PORT:=8076}"
fi
export OCTANE_HOST OCTANE_PORT WS_HOST WS_PORT CADDY_LISTEN_PORT
export OCTANE_INTERNAL_PORT="${OCTANE_PORT}"

# ---------------------------------------------------------------------------
# Auto-tune worker counts based on the host (CPU + memory).
#
# Heuristic: each PHP worker (Octane/Horizon) costs ~80 MiB. After reserving
# ~300 MiB for the always-on processes (caddy/redis/ws-server/masters), divide
# the remaining budget across roles.  Any user-set ENV wins.
# ---------------------------------------------------------------------------
detect_cpus() {
    if [ -r /sys/fs/cgroup/cpu.max ]; then
        # cgroup v2: "<quota> <period>" or "max <period>"
        read -r q p < /sys/fs/cgroup/cpu.max 2>/dev/null
        if [ "$q" != "max" ] && [ -n "$q" ] && [ -n "$p" ] && [ "$p" -gt 0 ]; then
            echo $(( (q + p - 1) / p ))
            return
        fi
    fi
    nproc 2>/dev/null || echo 1
}

detect_mem_mib() {
    if [ -r /sys/fs/cgroup/memory.max ]; then
        m=$(cat /sys/fs/cgroup/memory.max 2>/dev/null)
        if [ "$m" != "max" ] && [ -n "$m" ]; then
            echo $(( m / 1024 / 1024 ))
            return
        fi
    fi
    # No cgroup limit: avoid over-provisioning on big hosts. Cap the assumed
    # budget to MEM_FALLBACK_MIB (default 1024) unless the user opts out by
    # setting it explicitly. Use whichever is smaller of MemAvailable and cap.
    avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 1024)
    cap=${MEM_FALLBACK_MIB:-1024}
    [ "$avail" -lt "$cap" ] && echo "$avail" || echo "$cap"
}

CPUS=$(detect_cpus)
MEM_MIB=$(detect_mem_mib)

# Resource profile presets. RESOURCE_PROFILE selects ratios for the budget split:
#   minimal     - smallest possible footprint (~250-350 MiB), single octane worker,
#                 horizon capped to 1/1/1. Suitable for VPS with <=512 MiB RAM.
#   balanced    - default; ~80 MiB per worker, octane gets 25% of slots.
#   performance - larger reserves for opcache/caches, more aggressive horizon caps.
#   auto        - same as balanced.
: "${RESOURCE_PROFILE:=auto}"
case "$RESOURCE_PROFILE" in
    minimal)     RESERVED_MIB=200; SLOT_MIB=100; OCT_NUM=1; OCT_DEN=1; OCT_FORCE=1; auto_horizon_mem=128; auto_octane_gc=64 ;;
    performance) RESERVED_MIB=400; SLOT_MIB=70;  OCT_NUM=1; OCT_DEN=3; OCT_FORCE=0; auto_horizon_mem=384; auto_octane_gc=256 ;;
    balanced|auto|*) RESERVED_MIB=300; SLOT_MIB=80;  OCT_NUM=1; OCT_DEN=4; OCT_FORCE=0; auto_horizon_mem=256; auto_octane_gc=128 ;;
esac

BUDGET=$(( MEM_MIB - RESERVED_MIB ))
[ "$BUDGET" -lt "$SLOT_MIB" ] && BUDGET=$SLOT_MIB
SLOTS=$(( BUDGET / SLOT_MIB ))

clamp() { v=$1; lo=$2; hi=$3; [ "$v" -lt "$lo" ] && v=$lo; [ "$v" -gt "$hi" ] && v=$hi; echo "$v"; }

if [ "$OCT_FORCE" = "1" ]; then
    auto_octane=1
    auto_dp=1; auto_biz=1; auto_notif=1
else
    auto_octane=$(clamp $(( (SLOTS * OCT_NUM) / OCT_DEN )) 1 "$CPUS")
    remaining=$(( SLOTS - auto_octane - 2 ))
    [ "$remaining" -lt 3 ] && remaining=3
    auto_dp=$(clamp $(( remaining / 2 )) 1 $(( CPUS * 2 )))
    auto_biz=$(clamp $(( remaining / 4 )) 1 "$CPUS")
    auto_notif=$(clamp $(( remaining / 4 )) 1 "$CPUS")
fi

# User-set ENV always wins.
: "${OCTANE_WORKERS:=$auto_octane}"
: "${OCTANE_TASK_WORKERS:=1}"
: "${OCTANE_MAX_REQUESTS:=500}"
: "${OCTANE_GARBAGE_MB:=$auto_octane_gc}"
: "${OCTANE_MAX_EXECUTION_TIME:=60}"
: "${HORIZON_DATA_PIPELINE_MAX:=$auto_dp}"
: "${HORIZON_BUSINESS_MAX:=$auto_biz}"
: "${HORIZON_NOTIFICATION_MAX:=$auto_notif}"
: "${HORIZON_WORKER_MEMORY_MB:=$auto_horizon_mem}"
: "${HORIZON_WORKER_MAX_TIME:=0}"
: "${HORIZON_WORKER_MAX_JOBS:=0}"

export OCTANE_WORKERS OCTANE_TASK_WORKERS OCTANE_MAX_REQUESTS \
       OCTANE_GARBAGE_MB OCTANE_MAX_EXECUTION_TIME \
       HORIZON_DATA_PIPELINE_MAX HORIZON_BUSINESS_MAX HORIZON_NOTIFICATION_MAX \
       HORIZON_WORKER_MEMORY_MB HORIZON_WORKER_MAX_TIME HORIZON_WORKER_MAX_JOBS \
       RESOURCE_PROFILE

echo "[entrypoint] Auto-tune (profile=${RESOURCE_PROFILE}): cpus=${CPUS} mem=${MEM_MIB}MiB slots=${SLOTS} -> octane=${OCTANE_WORKERS} horizon(dp/biz/notif)=${HORIZON_DATA_PIPELINE_MAX}/${HORIZON_BUSINESS_MAX}/${HORIZON_NOTIFICATION_MAX} horizon_worker_mem=${HORIZON_WORKER_MEMORY_MB}MB"
echo "[entrypoint] Horizon supervisors use balance=auto with minProcesses=1, so they scale up to the cap on demand and back down when idle."

redis_reachable() {
    local host port
    host=$(grep -E '^REDIS_HOST=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    port=$(grep -E '^REDIS_PORT=' /www/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    command -v redis-cli >/dev/null 2>&1 || return 1
    [ -n "$host" ] || return 1
    case "$host" in
        /*) [ -S "$host" ] && redis-cli -s "$host" ping 2>/dev/null | grep -q PONG ;;
        *)  redis-cli -h "$host" -p "${port:-6379}" ping 2>/dev/null | grep -q PONG ;;
    esac
}

# ---------------------------------------------------------------------------
# Ensure the admin panel SPA (React/Vite build) is present at
# /www/public/assets/admin and is at least structurally valid.
#
# The build comes from cedar2025/xboard-admin-dist (git submodule in .gitmodules).
# We re-materialise it at container start when:
#   * the folder is missing (e.g. user mounted a volume wiping /www/public/assets/admin),
#   * or manifest.json is missing/corrupt (partial / failed copy).
# ---------------------------------------------------------------------------
ADMIN_DIR="/www/public/assets/admin"
ADMIN_DIST_REPO="${ADMIN_DIST_REPO:-https://github.com/cedar2025/xboard-admin-dist.git}"

# ---------------------------------------------------------------------------
# T18 self-healing: restore missing plugins/theme dirs from the baked-in image
# snapshot.  This handles the common docker-compose bind-mount scenario where user
# has empty `./plugins` -> /www/plugins` (or theme) and every plugin shows 404.
# Rule: COPY ONLY MISSING directories — never overwrite user changes (if the dir
# already exists, we leave it alone, even if old).
# ---------------------------------------------------------------------------
materialise_missing_from_image() {
    src_root="$1"
    dst_root="$2"
    [ -d "${src_root}" ] || return 0
    mkdir -p "${dst_root}"
    find "${src_root}" -mindepth 1 -maxdepth 1 -type d | while read -r src_item; do
        name="$(basename "${src_item}")"
        if [ ! -e "${dst_root}/${name}" ]; then
            echo "[entrypoint] T18: restoring missing ${dst_root}/${name} from image snapshot"
            cp -a "${src_item}" "${dst_root}/${name}" || \
                echo "[entrypoint] WARNING: failed to restore ${dst_root}/${name}" >&2
        fi
    done
    # final perms
    chown -R www:www "${dst_root}" 2>/dev/null || true
}
echo "[entrypoint] T18: materialising missing plugins/theme from image snapshot..."
materialise_missing_from_image "/www/.image-src/plugins"      "/www/plugins"
materialise_missing_from_image "/www/.image-src/plugins-core" "/www/plugins-core"
materialise_missing_from_image "/www/.image-src/theme"        "/www/theme"
echo "[entrypoint] T18: done. Plugins dir: $(find /www/plugins -maxdepth 1 -type d | wc -l) entries. Theme dir: $(find /www/theme -maxdepth 1 -type d | wc -l) entries."

# ---------------------------------------------------------------------------
# Admin SPA CJK -> RU patcher.  The upstream xboard-admin-dist React bundle is
# compiled with hardcoded zh-CN strings visible on buttons/cards/tabs.  Because we
# cannot recompile the SPA here we patch the shipped JS/CSS/HTML files in place
# after materialisation.  Substitutions are order-sensitive (longest first so we
# don't clobber partial substrings).
# ---------------------------------------------------------------------------
patch_admin_cjk_to_ru() {
    [ -d "${ADMIN_DIR}" ] || return 0
    echo "[entrypoint] Patching admin bundle CJK glyphs -> Russian (in-place sed on JS/CSS/HTML)..."
    find "${ADMIN_DIR}" -type f \( -name '*.js' -o -name '*.css' -o -name '*.html' -o -name '*.json' \) -print0 \
    | xargs -0 sed -i \
        -e 's/支付方式/Способ оплаты/g' \
        -e 's/未安装/Не установлено/g' \
        -e 's/没有安装/Не установлено/g' \
        -e 's/请先禁用插件后再卸载/⚠️ Сначала отключите плагин перед удалением/g' \
        -e 's/该插件为系统核心插件，不允许删除/🚫 Системный плагин, удаление запрещено/g' \
        -e 's/插件安装失败/❌ Ошибка установки плагина/g' \
        -e 's/插件安装成功/✅ Плагин установлен/g' \
        -e 's/插件卸载失败/❌ Ошибка удаления плагина/g' \
        -e 's/插件卸载成功/✅ Плагин удалён/g' \
        -e 's/插件升级失败/❌ Ошибка обновления плагина/g' \
        -e 's/插件升级成功/✅ Плагин обновлён/g' \
        -e 's/插件启用失败/❌ Ошибка включения плагина/g' \
        -e 's/插件启用成功/✅ Плагин включён/g' \
        -e 's/插件禁用成功/✅ Плагин отключён/g' \
        -e 's/插件上传失败/❌ Ошибка загрузки плагина/g' \
        -e 's/插件上传成功/✅ Плагин загружен/g' \
        -e 's/插件包大小不能超过10MB/Размер архива плагина не может превышать 10 МБ/g' \
        -e 's/获取配置失败/❌ Ошибка чтения конфигурации/g' \
        -e 's/配置更新失败/❌ Ошибка сохранения настроек/g' \
        -e 's/配置更新成功/✅ Настройки сохранены/g' \
        -e 's/管理后台/Админ-панель/g' \
        -e 's/仪表盘/Панель управления/g' \
        -e 's/概览/Обзор/g' \
        -e 's/系统/Система/g' \
        -e 's/设置/Настройки/g' \
        -e 's/配置/Настройка/g' \
        -e 's/用户/Пользователи/g' \
        -e 's/订单/Заказы/g' \
        -e 's/套餐/Тарифы/g' \
        -e 's/节点/Узлы/g' \
        -e 's/订阅/Подписка/g' \
        -e 's/流量/Трафик/g' \
        -e 's/插件/Плагины/g' \
        -e 's/主题/Тема/g' \
        -e 's/日志/Логи/g' \
        -e 's/工单/Тикеты/g' \
        -e 's/卡券/Промокоды/g' \
        -e 's/公告/Новости/g' \
        -e 's/文档/Документация/g' \
        -e 's/客户端/Клиенты/g' \
        -e 's/下载/Скачать/g' \
        -e 's/安装/Установить/g' \
        -e 's/卸载/Удалить/g' \
        -e 's/升级/Обновить/g' \
        -e 's/启用/Включить/g' \
        -e 's/禁用/Отключить/g' \
        -e 's/删除/Удалить/g' \
        -e 's/保存/Сохранить/g' \
        -e 's/取消/Отмена/g' \
        -e 's/提交/Отправить/g' \
        -e 's/确定/Ок/g' \
        -e 's/创建/Создать/g' \
        -e 's/编辑/Редактировать/g' \
        -e 's/查看/Просмотр/g' \
        -e 's/操作/Действия/g' \
        -e 's/状态/Статус/g' \
        -e 's/名称/Имя/g' \
        -e 's/价格/Цена/g' \
        -e 's/说明/Описание/g' \
        -e 's/邮箱/E-mail/g' \
        -e 's/手机号/Телефон/g' \
        -e 's/密码/Пароль/g' \
        -e 's/登录/Войти/g' \
        -e 's/注册/Регистрация/g' \
        -e 's/退出/Выйти/g' \
        -e 's/余额/Баланс/g' \
        -e 's/推广/Партнёрка/g' \
        -e 's/邀请码/Промокод/g' \
        -e 's/总计/Всего/g' \
        -e 's/合计/Итого/g' \
        -e 's/已开启/Вкл/g' \
        -e 's/已关闭/Выкл/g' \
        -e 's/已过期/Истёк/g' \
        -e 's/已完成/Завершён/g' \
        -e 's/已取消/Отменён/g' \
        -e 's/等待中/Ожидание/g' \
        -e 's/处理中/В работе/g' \
        -e 's/进行中/Активен/g' \
        -e 's/无限/Безлимит/g' \
        -e 's/有效期/Срок действия/g' \
        -e 's/剩余/Остаток/g' \
        -e 's/已用/Использовано/g' \
        -e 's/总共/Всего/g' \
        -e 's/成功/Успешно/g' \
        -e 's/失败/Ошибка/g' \
        -e 's/提示/Подсказка/g' \
        -e 's/个人中心/Личный кабинет/g' \
        -e 's/我的/Мой/g' \
        -e 's/帮助/Помощь/g' \
        -e 's/关于/О нас/g' \
        2>/dev/null || true
    echo "[entrypoint] Admin CJK patch done."
}

materialise_admin_spa() {
    # --- 0. Fast offline restore first: use the image-built snapshot if it
    #        exists.  Bypasses ALL network issues entirely when admin SPA was
    #        already materialised during docker build (the common / happy path).
    if [ -d /www/.image-src/admin ] && [ -f /www/.image-src/admin/manifest.json ] && [ -s /www/.image-src/admin/manifest.json ]; then
        echo "[entrypoint] Admin SPA: restoring offline from /www/.image-src/admin snapshot (baked in image during docker build)."
        mkdir -p /www/public/assets
        rm -rf "${ADMIN_DIR}" 2>/dev/null || true
        if cp -a /www/.image-src/admin "${ADMIN_DIR}"; then
            chown -R www:www "${ADMIN_DIR}" 2>/dev/null || true
            echo "[entrypoint] Admin SPA materialised offline: $(find "${ADMIN_DIR}" -type f | wc -l) files"
            return 0
        fi
        echo "[entrypoint] WARNING: offline snapshot restore failed, falling back to network..." >&2
    fi

    echo "[entrypoint] Admin SPA missing or corrupt; materialising from ${ADMIN_DIST_REPO} ..."
    mkdir -p /www/public/assets
    tmpdir="$(mktemp -d)"
    OK=0

    # --- 1. Retry git clone with back-off (3 attempts, 3s/6s/9s delay)
    for attempt in 1 2 3; do
        echo "[entrypoint]   git clone attempt ${attempt}/3: ${ADMIN_DIST_REPO}"
        if git clone --depth=1 "${ADMIN_DIST_REPO}" "${tmpdir}" >/tmp/adminspa-git.log 2>&1; then
            echo "[entrypoint]   git clone SUCCESS on attempt ${attempt}."
            OK=1
            break
        fi
        echo "[entrypoint]   git clone attempt ${attempt} FAILED: $(tail -n 3 /tmp/adminspa-git.log 2>/dev/null | tr '\n' ' ')" >&2
        rm -rf "${tmpdir}" 2>/dev/null; mkdir -p "${tmpdir}"
        sleep $(( attempt * 3 ))
    done

    # --- 2. Fallback: download ZIP via curl from codeload (no git needed —
    #        works if only TCP/443 HTTPS is open, not git:// / SSH)
    if [ "${OK}" != "1" ]; then
        ZIP_URL="https://codeload.github.com/cedar2025/xboard-admin-dist/zip/refs/heads/main"
        echo "[entrypoint]   git clone failed after 3 attempts; fallback curl ZIP download: ${ZIP_URL}"
        ZIP_TMP="/tmp/xboard-admin-dist.zip"
        for attempt in 1 2 3; do
            if (command -v curl >/dev/null 2>&1 && curl -fsSL --retry 3 --retry-delay 3 -o "${ZIP_TMP}" "${ZIP_URL}") || \
               (command -v wget >/dev/null 2>&1 && wget -q --tries=3 -O "${ZIP_TMP}" "${ZIP_URL}"); then
                UNZIP_DIR="$(mktemp -d)"
                if (command -v unzip >/dev/null 2>&1 && unzip -q "${ZIP_TMP}" -d "${UNZIP_DIR}") || \
                   (command -v python3 >/dev/null 2>&1 && python3 -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "${ZIP_TMP}" "${UNZIP_DIR}"); then
                    TOP_DIR=$(find "${UNZIP_DIR}" -mindepth 1 -maxdepth 1 -type d -name 'xboard-admin-dist-*' -print -quit)
                    if [ -n "${TOP_DIR}" ] && [ -f "${TOP_DIR}/manifest.json" ]; then
                        mv "${TOP_DIR}"/* "${tmpdir}/" 2>/dev/null
                        [ -f "${tmpdir}/manifest.json" ] && OK=1 && echo "[entrypoint]   ZIP curl download + extract SUCCESS on attempt ${attempt}."
                    fi
                fi
                rm -rf "${UNZIP_DIR}"
            fi
            [ "${OK}" = "1" ] && break
            echo "[entrypoint]   curl ZIP download attempt ${attempt} FAILED. Retrying in $((attempt*3))s..." >&2
            rm -f "${ZIP_TMP}" 2>/dev/null
            sleep $(( attempt * 3 ))
        done
        rm -f "${ZIP_TMP}" 2>/dev/null
    fi

    # --- 3. Last-ditch fallback: download minimal critical files one by one
    #        via jsdelivr CDN (CDN edge is almost never blocked).
    if [ "${OK}" != "1" ]; then
        echo "[entrypoint]   ZIP download also failed; last-ditch jsdelivr CDN per-file download..." >&2
        MANIFEST_URL="https://cdn.jsdelivr.net/gh/cedar2025/xboard-admin-dist@main/manifest.json"
        if (command -v curl >/dev/null 2>&1 && curl -fsSL --retry 2 -o "${tmpdir}/manifest.json" "${MANIFEST_URL}") || \
           (command -v wget >/dev/null 2>&1 && wget -q --tries=2 -O "${tmpdir}/manifest.json" "${MANIFEST_URL}"); then
            OK=1
            echo "[entrypoint]   jsdelivr manifest.json OK — admin SPA partially materialised (re-run container later for full bundle when network allows)."
        else
            echo "[entrypoint]   jsdelivr CDN also unreachable." >&2
        fi
    fi

    if [ "${OK}" = "1" ]; then
        rm -rf "${ADMIN_DIR}"
        mv "${tmpdir}" "${ADMIN_DIR}"
        rm -rf "${ADMIN_DIR}/.git" "${ADMIN_DIR}/.github" 2>/dev/null || true
        chown -R www:www "${ADMIN_DIR}" 2>/dev/null || true
        echo "[entrypoint] Admin SPA materialised: $(find "${ADMIN_DIR}" -type f | wc -l) files"
    else
        echo "[entrypoint] CRITICAL WARNING: all 3 admin SPA materialisation methods (git/curl/jsdelivr) FAILED.  Admin panel at the secure path will return a blank/white page.  Fix your outbound HTTPS to github.com and codeload.github.com, or run these 3 commands on the VPS host manually:
            mkdir -p /opt/xboard/_persistent_public/assets
            curl -fsSL https://codeload.github.com/cedar2025/xboard-admin-dist/zip/refs/heads/main -o /tmp/a.zip && unzip -q /tmp/a.zip -d /tmp/a && cp -a /tmp/a/xboard-admin-dist-*/* /opt/xboard/_persistent_public/assets/admin && rm -rf /tmp/a /tmp/a.zip
            Then mount '_persistent_public/assets/admin:/www/public/assets/admin:ro' in compose.yaml and restart." >&2
        rm -rf "${tmpdir}" 2>/dev/null
        return 1
    fi
}

# Also restore admin SPA from image snapshot at this point before the
# directory-existence check below — this catches the case where
# /www/.image-src/admin is populated but the bind-mount target at the
# destination is an empty dir wiping the real files.
if [ -d /www/.image-src/admin ] && [ -f /www/.image-src/admin/manifest.json ] && [ -s /www/.image-src/admin/manifest.json ]; then
    if [ ! -f "${ADMIN_DIR}/manifest.json" ] || [ ! -s "${ADMIN_DIR}/manifest.json" ]; then
        echo "[entrypoint] Admin SPA: pre-restore from image snapshot before existence-check (bind-mount shadow detected)."
        mkdir -p /www/public/assets
        rm -rf "${ADMIN_DIR}" 2>/dev/null || true
        cp -a /www/.image-src/admin "${ADMIN_DIR}" || true
        chown -R www:www "${ADMIN_DIR}" 2>/dev/null || true
    fi
fi

if [ ! -d "${ADMIN_DIR}" ] || [ ! -f "${ADMIN_DIR}/manifest.json" ] || [ ! -s "${ADMIN_DIR}/manifest.json" ]; then
    materialise_admin_spa
fi
patch_admin_cjk_to_ru

# ---------------------------------------------------------------------------
# Detect "installed" state: INSTALLED=1 in .env  AND  core tables exist in DB
# (handles edge case when user put INSTALLED=1 manually but install never
# actually finished / ran migrations → v2_system_config missing → SQL errors).
# ---------------------------------------------------------------------------
ENV_INSTALLED=0
if [ -s /www/.env ] && grep -qE '^INSTALLED=(1|true)$' /www/.env; then
    ENV_INSTALLED=1
fi
DB_TABLES_EXIST=0
DB_TYPE_FROM_ENV=""
if [ -s /www/.env ]; then
    DB_TYPE_FROM_ENV=$(grep -E '^DB_CONNECTION=' /www/.env 2>/dev/null | cut -d= -f2 | tr -d '"' | tr -d "'" | tr '[:upper:]' '[:lower:]' || echo "")
fi
if [ "$DB_TYPE_FROM_ENV" = "sqlite" ] || [ "$DB_TYPE_FROM_ENV" = "" ]; then
    DB_PATH_FROM_ENV=""
    if [ -s /www/.env ]; then
        DB_PATH_FROM_ENV=$(grep -E '^DB_DATABASE=' /www/.env 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'" || echo "")
    fi
    [ -z "${DB_PATH_FROM_ENV}" ] && DB_PATH_FROM_ENV=".docker/.data/xboard.sqlite"
    case "${DB_PATH_FROM_ENV}" in /*) ;; *) DB_PATH_FROM_ENV="/www/${DB_PATH_FROM_ENV}" ;; esac
    if [ -s "${DB_PATH_FROM_ENV}" ]; then
        TBL_COUNT=$(sqlite3 "${DB_PATH_FROM_ENV}" "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('v2_system_config','users');" 2>/dev/null || echo "0")
        case "${TBL_COUNT}" in
            *[!0-9]*) TBL_COUNT=0 ;;
        esac
        if [ "${TBL_COUNT:-0}" -ge 1 ]; then
            DB_TABLES_EXIST=1
        fi
    fi
else
    # MySQL/Postgres: assume tables exist if env says INSTALLED=1 (too complex to probe here)
    if [ "${ENV_INSTALLED}" = "1" ]; then
        DB_TABLES_EXIST=1
    fi
fi
RUN_INSTALL_HINT=0
if [ "${ENV_INSTALLED}" != "1" ] || [ "${DB_TABLES_EXIST}" != "1" ]; then
    RUN_INSTALL_HINT=1
fi
RUNNING_INSTALL_CLI=0
echo " $* " | grep -q ' xboard:install ' && RUNNING_INSTALL_CLI=1

if [ ! -s /www/.env ] || [ "${RUN_INSTALL_HINT}" = "1" ] || [ "${RUNNING_INSTALL_CLI}" = "1" ]; then
    if [ "${ENV_INSTALLED}" = "1" ] && [ "${DB_TABLES_EXIST}" != "1" ]; then
        echo "[entrypoint] ⚠️  .env says INSTALLED=1 but DB core tables missing (v2_system_config/users not found) → treating as NOT installed, re-running AUTO_INSTALL."
    fi
    echo "[entrypoint] Skipping xboard:update (not yet installed or running xboard:install)."
    # -----------------------------------------------------------------------
    # Headless AUTO_INSTALL: when user sets AUTO_INSTALL=1 via env the
    # XboardInstall artisan command has an ENV-driven "non-interactive"
    # branch that reads DB/Redis/Admin credentials from environment variables.
    # This matches the 1-click `curl install.sh | bash` flow so users never
    # have to manually attach to a container and run artisan install.
    # -----------------------------------------------------------------------
    RUN_PLUGIN_HEAL_AFTER=0
    if [ "${AUTO_INSTALL:-0}" = "1" ] || [ "${AUTO_INSTALL:-0}" = "true" ]; then
        echo "[entrypoint] AUTO_INSTALL=${AUTO_INSTALL} detected — running php artisan xboard:install --no-interaction..."
        # Use array/sync drivers so early tinker steps never block on
        # misconfigured redis (xboard:install itself writes the real REDIS_*
        # values into .env after the user's env-provided ones are validated)
        set +e
        CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
            php /www/artisan xboard:install --no-interaction
        RC=$?
        set -e
        if [ "$RC" -eq 0 ]; then
            echo "[entrypoint] xboard:install succeeded (rc=$RC)."
            RUN_PLUGIN_HEAL_AFTER=1
        else
            echo "[entrypoint] WARNING: xboard:install rc=$RC; continuing boot so you can inspect inside container and re-run artisan manually." >&2
        fi
        unset RC
    fi
    # -----------------------------------------------------------------------
    # Plugin self-heal — also run right after a successful fresh AUTO_INSTALL
    # (not only on xboard:update path) so the plugin rows appear in DB even
    # if the install command itself did not call installDefaultPlugins.
    # -----------------------------------------------------------------------
    if [ "${RUN_PLUGIN_HEAL_AFTER}" = "1" ]; then
        set +e
        echo "[entrypoint] Self-healing plugins after fresh install (PluginManager::installDefaultPlugins)..."
        PLUGIN_HEAL_OUTPUT=$(CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
            php /www/artisan tinker --execute="
                try {
                    \App\Services\Plugin\PluginManager::installDefaultPlugins();
                    \$rows = \App\Models\Plugin::all(['code','name','version','type','is_enabled','installed_at'])
                        ->map(fn(\$p)=>implode(' | ',\$p->toArray()))->toArray();
                    echo 'AFTER_HEAL OK. plugins_count=' . count(\$rows) . PHP_EOL;
                    foreach(\$rows as \$r){ echo '  ║ ' . \$r . PHP_EOL; }
                } catch (\\Throwable \$e) {
                    echo 'AFTER_HEAL FAIL: ' . \$e->getMessage() . ' (in ' . \$e->getFile() . ':' . \$e->getLine() . ')' . PHP_EOL;
                    exit(1);
                }
            " 2>&1)
        PLUGIN_HEAL_RC=$?
        echo "$PLUGIN_HEAL_OUTPUT"
        if [ "$PLUGIN_HEAL_RC" -ne 0 ]; then
            echo "[entrypoint] WARNING: plugin self-heal rc=$PLUGIN_HEAL_RC. Continuing anyway so container boots." >&2
        fi
        set -e
    fi
else
    if redis_reachable; then
        echo "[entrypoint] Running xboard:update (redis reachable, real drivers)..."
        php /www/artisan xboard:update --no-interaction || \
            echo "[entrypoint] WARNING: xboard:update failed; continuing so supervisor can boot anyway." >&2
    else
        echo "[entrypoint] Running xboard:update (redis not yet up, using array/sync drivers)..."
        CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
            php /www/artisan xboard:update --no-interaction || \
            echo "[entrypoint] WARNING: xboard:update failed; continuing so supervisor can boot anyway." >&2
    fi
    # -----------------------------------------------------------------------
    # Self-heal plugins on every container boot:
    #   1) installDefaultPlugins scans plugins/ and plugins-core/ dirs
    #      → installs plugin in DB if config.json exists but no row in v2_plugins
    #      → ENABLES plugin automatically if code in alwaysEnableCodes (olc_rtc)
    #   2) Never crashes container — set +e + only warn on stderr
    # -----------------------------------------------------------------------
    set +e
    echo "[entrypoint] Self-healing plugins (PluginManager::installDefaultPlugins)..."
    PLUGIN_HEAL_OUTPUT=$(CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
        php /www/artisan tinker --execute="
            try {
                \App\Services\Plugin\PluginManager::installDefaultPlugins();
                \$rows = \App\Models\Plugin::all(['code','name','version','type','is_enabled','installed_at'])
                    ->map(fn(\$p)=>implode(' | ',\$p->toArray()))->toArray();
                echo 'AFTER_HEAL OK. plugins_count=' . count(\$rows) . PHP_EOL;
                foreach(\$rows as \$r){ echo '  ║ ' . \$r . PHP_EOL; }
            } catch (\\Throwable \$e) {
                echo 'AFTER_HEAL FAIL: ' . \$e->getMessage() . ' (in ' . \$e->getFile() . ':' . \$e->getLine() . ')' . PHP_EOL;
                exit(1);
            }
        " 2>&1)
    PLUGIN_HEAL_RC=$?
    echo "$PLUGIN_HEAL_OUTPUT"
    if [ "$PLUGIN_HEAL_RC" -ne 0 ]; then
        echo "[entrypoint] WARNING: plugin self-heal rc=$PLUGIN_HEAL_RC. Continuing anyway so container boots." >&2
    fi
    set -e
fi

echo "[entrypoint] Starting services (caddy=${ENABLE_CADDY} web=${ENABLE_WEB} horizon=${ENABLE_HORIZON} ws=${ENABLE_WS_SERVER})..."
# Drop stale Octane/WorkerMan state files so the new master does not signal
# PIDs left over from a previous container run (causes Swoole kill EPERM).
rm -f /www/storage/logs/octane-server-state.json /www/storage/logs/xboard-ws-server.pid 2>/dev/null || true
chown -R www:www /www 2>/dev/null || true
chown redis:redis /data 2>/dev/null || true
exec "$@"
