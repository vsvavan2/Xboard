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

if [ -n "$compose_file" ] && command -v docker >/dev/null 2>&1; then
  echo "==> Docker deployment detected. Rebuilding images and restarting services..."
  docker compose -f "$compose_file" build xboard olcrtc-manager
  docker compose -f "$compose_file" up -d --force-recreate xboard olcrtc-manager
  docker compose -f "$compose_file" exec xboard php artisan optimize:clear
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
