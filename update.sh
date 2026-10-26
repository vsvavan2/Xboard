#!/bin/sh
set -eu

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  echo "Git is not installed! Please install git and try again."
  exit 1
fi

repo_root="$(pwd)"

git_safe() {
  git -c safe.directory="$repo_root" "$@"
}

compose_file=""
if [ -f "compose.yaml" ]; then
  compose_file="compose.yaml"
elif [ -f "docker-compose.yml" ]; then
  compose_file="docker-compose.yml"
fi

git_safe fetch --all --tags
git_safe reset --hard origin/master
git_safe submodule update --init --recursive --force

# Оставшаяся с прежних установок КОПИЯ системной темы в storage/theme
# «затеняет» обновлённый theme/<name>: маршрут отдаёт системную тему, а
# рендерится старый шаблон → в ЛК пропадают виджет/тест/ключ OlcRTC.
# Системные темы всегда берём из репозитория (theme/), копии не нужны.
for t in Xboard v2board; do
  if [ -d "storage/theme/$t" ]; then
    echo "==> Removing stale system theme copy: storage/theme/$t"
    rm -rf "storage/theme/$t"
  fi
done

# ---------------------------------------------------------------------------
# Само-восстановление автоматического деплоя (cron).
# Кладём актуальный скрипт из репозитория в /usr/local/bin и гарантируем запись
# в crontab (каждые 5 минут). Замена — атомарная (mv), поэтому уже запущенный
# из старого inode процесс cron-деплоя не ломается: новый файл подхватит
# следующий запуск. Если crontab нет (напр. локальная сборка) — просто пропускаем.
# ---------------------------------------------------------------------------
if [ -f "auto-deploy.sh" ]; then
  cp auto-deploy.sh /usr/local/bin/.xboard-autodeploy.new 2>/dev/null \
    && mv -f /usr/local/bin/.xboard-autodeploy.new /usr/local/bin/xboard-autodeploy 2>/dev/null \
    && chmod 755 /usr/local/bin/xboard-autodeploy 2>/dev/null \
    && echo "==> autodeploy: /usr/local/bin/xboard-autodeploy refreshed."
  if command -v crontab >/dev/null 2>&1; then
    if ! crontab -l 2>/dev/null | grep -q 'xboard-autodeploy'; then
      (crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/xboard-autodeploy >/dev/null 2>&1") | crontab - 2>/dev/null \
        && echo "==> autodeploy: cron entry ensured."
    fi
  fi
fi

# ---------------------------------------------------------------------------
# Обновление «ядра» olcrtc (openlibrecommunity/olcrtc).
# Бинарник olcrtc вшит в образ olcrtc-manager (olcrtc-manager.Dockerfile).
# Обычный `docker compose build` переиспользует закэшированный слой и НЕ тянет
# новый код ядра. Узнаём SHA последнего коммита апстрима и прокидываем его как
# build-arg OLCRTC_COMMIT (входит в cache-key) → Docker реально пересобирает ядро.
# Нет сети/лимит GitHub API — просто продолжаем со старым ядром, общий апдейт не
# ломаем. Точный статус/ручное обновление: sh update-olcrtc.sh [--status|--check].
olcrtc_ref="${OLCRTC_RELEASE:-master}"
if [ -f .env ]; then
  _env_ref="$(grep -E '^OLCRTC_RELEASE=' .env 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"'\''\r ' || true)"
  if [ -n "$_env_ref" ]; then olcrtc_ref="$_env_ref"; fi
fi
if command -v curl >/dev/null 2>&1; then
  _sha="$(curl -fsSL --max-time 20 \
        -H 'Accept: application/vnd.github+json' \
        -H 'User-Agent: xboard-update' \
        "https://api.github.com/repos/openlibrecommunity/olcrtc/commits?sha=${olcrtc_ref}&per_page=1" \
        2>/dev/null | grep -o '"sha": *"[0-9a-f]\{40\}"' | head -n1 | grep -o '[0-9a-f]\{40\}' || true)"
  if [ -n "$_sha" ]; then
    OLCRTC_COMMIT="$_sha"
    export OLCRTC_COMMIT
    echo "==> olcrtc kernel will be rebuilt at ${olcrtc_ref}@${_sha}"
  else
    echo "==> WARN: не удалось узнать коммит olcrtc в апстриме (нет сети?); оставляю текущее ядро."
  fi
fi

if [ -n "$compose_file" ] && command -v docker >/dev/null 2>&1; then
  echo "==> Docker deployment detected. Rebuilding images and restarting services..."
  docker compose -f "$compose_file" build xboard olcrtc-manager
  docker compose -f "$compose_file" up -d --force-recreate xboard olcrtc-manager
  # public/theme — снимок темы, сделанный при установке; маршрут "/" копирует его
  # заново только если папки нет. Удаляем, чтобы свежие assets (umi.js и пр.)
  # переехали из обновлённого theme/ при первом же запросе.
  docker compose -f "$compose_file" exec xboard sh -c 'rm -rf /www/public/theme/Xboard /www/public/theme/v2board' || true
  docker compose -f "$compose_file" exec xboard php artisan optimize:clear
  # Опциональный TLS-терминатор (HTTPS :443). Поднимаем/пересоздаём только если
  # в .env включён профиль compose "tls" (COMPOSE_PROFILES=tls). Нужен для
  # вебхуков платёжек, требующих HTTPS (напр. ЮKassa). Иначе — no-op.
  if grep -qE '^COMPOSE_PROFILES=.*tls' .env 2>/dev/null; then
    echo "==> TLS profile detected. (Re)starting caddy-tls (HTTPS :443)..."
    docker compose -f "$compose_file" up -d --force-recreate caddy-tls || true
  fi
  docker compose -f "$compose_file" ps
  exit 0
fi

echo "==> Non-Docker deployment detected. Updating PHP dependencies on host..."
rm -f composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar update -vvv
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www "$(pwd)"
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi
