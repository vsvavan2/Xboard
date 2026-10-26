#!/bin/sh
# =============================================================================
# update-olcrtc.sh — обновление «ядра» olcrtc (openlibrecommunity/olcrtc),
#                   т.е. того самого бинарника, который выдаёт ключи
#                   формата olcrtc://jitsi?datachannel@…
#
# ПОЧЕМУ ТАК, А НЕ ИНАЧЕ
#   Бинарник olcrtc компилируется ВНУТРЬ Docker-образа xboard-olcrtc-manager
#   (olcrtc-manager.Dockerfile). У апстрима openlibrecommunity/olcrtc НЕТ
#   GitHub-релизов (только ветка master и тег v0.0.1), поэтому «скачать готовый
#   бинарник» нельзя — обновление = пересборка образа на хосте.
#   Docker кэширует слои сборки, поэтому обычный `docker compose build`
#   подтянул бы СТАРЫЙ закэшированный бинарник. Этот скрипт узнаёт SHA
#   последнего коммита в апстриме и передаёт его как build-arg OLCRTC_COMMIT,
#   что инвалидирует кэш и реально пересобирает ядро.
#
# ЧТО ЭТО ДАЁТ
#   • одна команда вместо ручной возни с пересборкой;
#   • ключи клиентов (olcrtc://…) остаются прежними — переустанавливать
#     клиентам ничего не нужно, меняется только серверное ядро;
#   • можно включить автообновление по расписанию (cron) — тогда «всё всегда
#     работает чётко» без вашего участия.
#
# ИСПОЛЬЗОВАНИЕ
#   sh update-olcrtc.sh                 # проверить и обновить, если есть новое
#   sh update-olcrtc.sh --check         # только проверить (exit 10 = есть обновление)
#   sh update-olcrtc.sh --status        # что установлено и что в апстриме
#   sh update-olcrtc.sh --force         # пересобрать принудительно
#   sh update-olcrtc.sh --ref v0.0.1    # взять конкретный тег / ветку / SHA
#   sh update-olcrtc.sh --prune         # очистить кэш сборки перед сборкой (малый диск)
#   sh update-olcrtc.sh --install-cron  # включить авто-обновление раз в неделю
#   sh update-olcrtc.sh --uninstall-cron
#
# ТРЕБОВАНИЯ: docker + docker compose + curl. Запускать из каталога проекта
# (/opt/xboard), там же, где compose.yaml.
# =============================================================================
set -eu

REPO_SLUG="openlibrecommunity/olcrtc"
DEFAULT_REF="master"
STATE_FILE=".olcrtc-deployed"
LOG_FILE="storage/logs/olcrtc-update.log"
CRON_MARK="update-olcrtc.sh"

MODE="update"
FORCE=0
QUIET=0
DO_PRUNE=0
REF=""
REF_EXPLICIT=0

# Подхватываем OLCRTC_RELEASE из .env (если зафиксирована конкретная ветка/тег).
ENV_REF=""
if [ -f .env ]; then
  ENV_REF="$(grep -E '^OLCRTC_RELEASE=' .env 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"'\''\r' | tr -d ' ' || true)"
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --check)          MODE="check" ;;
    --status)         MODE="status" ;;
    --force|-f)       FORCE=1 ;;
    --quiet|-q)       QUIET=1 ;;
    --prune)          DO_PRUNE=1 ;;
    --install-cron)   MODE="install-cron" ;;
    --uninstall-cron) MODE="uninstall-cron" ;;
    --ref)            shift; REF="${1:-}" ; REF_EXPLICIT=1 ;;
    --ref=*)          REF="${1#--ref=}" ; REF_EXPLICIT=1 ;;
    -h|--help)        sed -n '2,40p' "$0" 2>/dev/null || true; exit 0 ;;
    *) echo "Неизвестный аргумент: $1 (см. --help)" >&2; exit 2 ;;
  esac
  shift
done

if [ "$REF_EXPLICIT" = "0" ]; then
  if [ -n "$ENV_REF" ]; then REF="$ENV_REF"; else REF="$DEFAULT_REF"; fi
fi

log() { if [ "$QUIET" != "1" ]; then printf '%s\n' "$*"; fi; }
err() { printf '%s\n' "$*" >&2; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || { err "ОШИБКА: не найдена команда '$1'. Установите её и повторите."; exit 1; }
}
need_cmd curl
need_cmd docker

compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "$@"
  else
    err "ОШИБКА: не найден ни 'docker compose', ни 'docker-compose'."
    exit 1
  fi
}

COMPOSE_FILE=""
for f in compose.yaml docker-compose.yml docker-compose.yaml; do
  if [ -f "$f" ]; then COMPOSE_FILE="$f"; break; fi
done
if [ -z "$COMPOSE_FILE" ]; then
  err "ОШИБКА: в $(pwd) нет compose.yaml / docker-compose.yml."
  err "Запускайте скрипт из каталога проекта (например, /opt/xboard)."
  exit 1
fi

# --- GitHub: последний коммит в выбранной ветке/теге -------------------------
REMOTE_SHA=""
REMOTE_DATE=""
fetch_remote() {
  _ref="$1"
  REMOTE_SHA=""; REMOTE_DATE=""
  _tmp="$(mktemp 2>/dev/null || echo "/tmp/olcrtc-api.$$")"
  if ! curl -fsSL --max-time 25 \
        -H 'Accept: application/vnd.github+json' \
        -H 'User-Agent: xboard-olcrtc-updater' \
        "https://api.github.com/repos/${REPO_SLUG}/commits?sha=${_ref}&per_page=1" \
        -o "$_tmp" 2>/dev/null; then
    rm -f "$_tmp" 2>/dev/null || true
    return 1
  fi
  REMOTE_SHA="$(grep -o '"sha": *"[0-9a-f]\{40\}"' "$_tmp" | head -n1 | grep -o '[0-9a-f]\{40\}' || true)"
  REMOTE_DATE="$(grep -o '"date": *"[0-9T:-]\{20\}"' "$_tmp" | head -n1 | grep -o '[0-9T:-]\{20\}' || true)"
  rm -f "$_tmp" 2>/dev/null || true
  [ -n "$REMOTE_SHA" ]
}

# --- Что реально установлено сейчас -----------------------------------------
installed_version() {
  _v=""
  _v="$(compose -f "$COMPOSE_FILE" exec -T olcrtc-manager cat /etc/olcrtc.version 2>/dev/null | tr -d '\r\n' || true)"
  if [ -z "$_v" ] && [ -f "$STATE_FILE" ]; then
    _v="$(tr -d '\r\n' < "$STATE_FILE" 2>/dev/null || true)"
  fi
  printf '%s' "$_v"
}

wait_healthy() {
  _i=0
  while [ "$_i" -lt 40 ]; do
    if compose -f "$COMPOSE_FILE" exec -T olcrtc-manager \
         wget -qO- --timeout=3 http://127.0.0.1:8080/healthz 2>/dev/null | grep -q '"status":"ok"'; then
      return 0
    fi
    _i=$((_i + 1))
    sleep 2
  done
  return 1
}

do_status() {
  _inst="$(installed_version)"
  log "Ядро olcrtc (установлено): ${_inst:-неизвестно}"
  log "Источник: ${REPO_SLUG}@${REF}"
  if fetch_remote "$REF"; then
    log "Апстрим (последний коммит): ${REMOTE_SHA}  ${REMOTE_DATE}"
    if [ -n "$_inst" ] && [ "$_inst" = "$REMOTE_SHA" ]; then
      log "Состояние: актуально"
    else
      log "Состояние: доступно обновление"
    fi
  else
    err "Не удалось получить данные из GitHub API (нет сети или превышен лимит)."
    return 1
  fi
}

do_update() {
  if ! fetch_remote "$REF"; then
    err "ОШИБКА: не удалось узнать последний коммит ${REPO_SLUG}@${REF}."
    err "Проверьте доступ к https://api.github.com из этой сети."
    exit 1
  fi

  _inst="$(installed_version)"
  log "Установлено : ${_inst:-неизвестно}"
  log "В апстриме  : ${REMOTE_SHA}  ${REMOTE_DATE}"

  if [ "$_inst" = "$REMOTE_SHA" ] && [ "$FORCE" != "1" ]; then
    log "Ядро olcrtc уже актуально — обновление не требуется."
    return 0
  fi

  if [ "$DO_PRUNE" = "1" ]; then
    log "==> Очищаю кэш сборки Docker (освобождение диска)…"
    docker builder prune -af >/dev/null 2>&1 || true
  fi

  # Пробрасываем SHA и флагом, и через окружение — Compose подставляет
  # ${OLCRTC_COMMIT:-} из env надёжнее, чем CLI --build-arg в старых версиях.
  OLCRTC_COMMIT="$REMOTE_SHA"
  export OLCRTC_COMMIT

  log "==> Пересобираю образ olcrtc-manager (commit=${REMOTE_SHA})…"
  if ! compose -f "$COMPOSE_FILE" build --build-arg "OLCRTC_COMMIT=${REMOTE_SHA}" olcrtc-manager; then
    err "ОШИБКА: сборка не удалась."
    err "На VDS с малым диском: docker builder prune -af && sh $0 --force --prune"
    exit 1
  fi

  log "==> Перезапускаю сервис olcrtc-manager…"
  compose -f "$COMPOSE_FILE" up -d --force-recreate olcrtc-manager

  if wait_healthy; then
    _new="$(compose -f "$COMPOSE_FILE" exec -T olcrtc-manager cat /etc/olcrtc.version 2>/dev/null | tr -d '\r\n' || true)"
    printf '%s' "${_new:-$REMOTE_SHA}" > "$STATE_FILE"
    log "Готово. Ядро olcrtc обновлено до ${_new:-$REMOTE_SHA}."
    log "Ключи olcrtc:// остаются прежними — клиентам ничего переустанавливать не нужно."
  else
    err "Сервис не вышел в healthy за отведённое время. Диагностика:"
    err "  docker compose -f $COMPOSE_FILE logs --tail=100 olcrtc-manager"
    exit 1
  fi
}

install_cron() {
  _abs_sh="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
  _wd="$(pwd)"
  mkdir -p "${_wd}/storage/logs" 2>/dev/null || true
  _line="30 4 * * 0 cd ${_wd} && sh ${_abs_sh} --quiet --prune >> ${_wd}/${LOG_FILE} 2>&1"
  _cur="$(crontab -l 2>/dev/null | grep -v "$CRON_MARK" || true)"
  { printf '%s\n' "$_cur"; printf '%s\n' "$_line"; } | grep -v '^$' | crontab -
  log "Автообновление включено: каждое воскресенье в 04:30."
  log "Лог: ${_wd}/${LOG_FILE}"
  log "Отключить: sh $0 --uninstall-cron"
}

uninstall_cron() {
  _cur="$(crontab -l 2>/dev/null | grep -v "$CRON_MARK" || true)"
  printf '%s\n' "$_cur" | grep -v '^$' | crontab - || true
  log "Автообновление отключено."
}

case "$MODE" in
  status) do_status ;;
  check)
    if ! fetch_remote "$REF"; then
      err "Не удалось получить данные из GitHub API (нет сети или превышен лимит)."
      exit 1
    fi
    _inst="$(installed_version)"
    if [ -n "$_inst" ] && [ "$_inst" = "$REMOTE_SHA" ]; then
      log "Ядро olcrtc актуально (${_inst})."
      exit 0
    fi
    log "Доступно обновление olcrtc: ${_inst:-неизвестно} -> ${REMOTE_SHA} (${REMOTE_DATE})"
    exit 10
    ;;
  install-cron)   install_cron ;;
  uninstall-cron) uninstall_cron ;;
  update)         do_update ;;
esac
