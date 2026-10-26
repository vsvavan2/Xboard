#!/bin/sh
# Xboard x OlcRTC - автоматический деплой на VDS по изменениям в GitHub.
#
# Каждые N минут проверяет origin/master. Если появились новые коммиты -
# выполняет `sh update.sh` (git reset --hard origin/master + пересборка образов
# + сброс кэша Laravel + чистка старых копий темы) и проверяет, что панель
# отвечает по HTTP.
#
# Установка (один раз, на VDS):
#   install -m 755 auto-deploy.sh /usr/local/bin/xboard-autodeploy
#   (crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/xboard-autodeploy >/dev/null 2>&1") | crontab -
#
# Лог:      /var/log/xboard-autodeploy.log
# Проверка: tail -n 40 /var/log/xboard-autodeploy.log
# Отключить: crontab -e  ->  удалить строку xboard-autodeploy

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

APP_DIR="${APP_DIR:-/opt/xboard}"
BRANCH="${BRANCH:-master}"
LOG="${LOG:-/var/log/xboard-autodeploy.log}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:7001/}"
LOCK="/tmp/xboard-autodeploy.lock"

log() { echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] $*" >> "$LOG"; }

# Защита от параллельного запуска (пересборка может идти дольше 5 минут).
if [ -e "$LOCK" ]; then
  pid=$(cat "$LOCK" 2>/dev/null || echo "")
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    exit 0
  fi
fi
echo $$ > "$LOCK"
trap 'rm -f "$LOCK"' EXIT INT TERM

cd "$APP_DIR" || { log "ERROR: нет каталога $APP_DIR"; exit 1; }

git fetch --quiet origin "$BRANCH" || { log "ERROR: git fetch не удался (нет сети/доступа к GitHub?)"; exit 1; }

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse "origin/$BRANCH")

if [ "$LOCAL" = "$REMOTE" ]; then
  exit 0
fi

log "=== Новый коммит: $(echo "$LOCAL" | cut -c1-7) -> $(echo "$REMOTE" | cut -c1-7). Запускаю update.sh"
if sh update.sh >> "$LOG" 2>&1; then
  log "OK: обновление завершено, HEAD=$(git rev-parse --short HEAD)"
else
  log "ERROR: update.sh завершился с ошибкой (подробности выше в логе)"
  exit 1
fi

# Ждём подъём панели (до ~90 сек) и фиксируем результат.
i=0
while [ "$i" -lt 18 ]; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$HEALTH_URL" || true)
  if [ "$code" = "200" ] || [ "$code" = "302" ]; then
    log "OK: панель отвечает $HEALTH_URL -> HTTP $code"
    exit 0
  fi
  i=$((i+1))
  sleep 5
done

log "ERROR: панель не ответила 200/302 за 90 сек. Проверьте: docker compose ps && docker compose logs --tail=80 xboard"
exit 1
