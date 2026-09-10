# INSTALL.md — Ручная пошаговая установка (без `curl | bash`)

> Этот документ описывает **ручной порядок установки** форка vsvavan2/Xboard на
> Ubuntu 22.04 / 24.04 или Debian 12 (x86_64 / aarch64). Рекомендуется для
> тех, кто хочет понимать, что делает скрипт, или для доработки форка под себя.
> Если хотите — используйте один-клик из README.

---

## Оглавление

1. [Требования](#1-требования)
2. [Шаг 0 — Подготовка сервера и Docker Engine](#2-шаг-0--подготовка-сервера-и-docker-engine)
3. [Шаг 1 — Клонирование и генерация `.env`](#3-шаг-1--клонирование-и-генерация-env)
4. [Шаг 2 — Первый запуск установщика (миграции, админ, плагины, SPA админки)](#4-шаг-2--первый-запуск-установщика-миграции-админ-плагины-spa-админки)
5. [Шаг 3 — Подъём всего стека и health checks](#5-шаг-3--подъём-всего-стека-и-health-checks)
6. [Шаг 4 — Создать DNS и HTTPS (по желанию, но рекомендуется)](#6-шаг-4--создать-dns-и-https-по-желанию-но-рекомендуется)
7. [Полный список переменных `.env`](#7-полный-список-переменных-env)
8. [Порты Docker Compose](#8-порты-docker-compose)
9. [Volumes — где живут данные](#9-volumes--где-живут-данные)

---

## 1. Требования

| Компонент       | Минимально                      | Рекомендуем                       |
|-----------------|---------------------------------|-----------------------------------|
| ОС              | Ubuntu 22.04 LTS (kernel 5.15)  | Ubuntu 24.04 LTS / Debian 12      |
| Архитектура     | x86_64                          | x86_64 / aarch64 (Raspberry Pi 5) |
| CPU             | 1 vCPU                          | 2 vCPU                            |
| ОЗУ             | 1.0 ГБ (упадёт в SWAP)         | 2.0 ГБ                            |
| Диск            | 10 ГБ SSD (sqlite БД + image)   | 30 ГБ NVMe                        |
| Порт 7001       | свободен от iptables INPUT      | порт 443 тоже открыт (HTTPS)      |
| Пользователь    | root (рекомендуется)            | sudo-пользователь с группой docker|

> ⚠️ **Важно:** OlcRTC manager запускает отдельный бинарник `olcrtc mode=srv` на
> каждого пользователя. На 1 ГБ ОЗУ максимум ~50 одновременных пользователей.
> Для 500+ пользователей минимум 4 ГБ ОЗУ и отдельный инстанс olcrtc-manager
> на выделенном хосте (вне Xboard compose, через внешний API-ключ).

---

## 2. Шаг 0 — Подготовка сервера и Docker Engine

Логиньтесь под **root** на свежий VPS (дроплет DigitalOcean / VDSina / Timeweb / Selectel).

```bash
# 0.1 Обновляем apt
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release sqlite3 git \
                   apt-transport-https

# 0.2 (рекомендуется) Выключаем classic Redis который часто ставится по умолчанию
# (слушает порт 6379 локально — будем использовать контейнерный Redis)
systemctl disable --now redis-server 2>/dev/null || true
systemctl disable --now nginx 2>/dev/null || true
systemctl disable --now apache2 2>/dev/null || true

# 0.3 Устанавливаем официальный Docker Engine (не docker.io из репозитория!)
curl -fsSL https://get.docker.com | sh

# 0.4 (если используете НЕ root-пользователя — добавьте в группу docker)
# usermod -aG docker ${USER}
# newgrp docker  # или relogin

# 0.5 Проверка:
docker version --format '{{.Server.Version}}'        # >= 27 ожидаем
docker compose version                              # >= v2.28 ожидаем
```

✅ Готово — Docker Engine работает.

---

## 3. Шаг 1 — Клонирование и генерация `.env`

### 3.1 Клонирование репозитория

```bash
git clone --depth 1 https://github.com/vsvavan2/Xboard /opt/xboard
cd /opt/xboard
```

> Ключ `--depth 1` значительно экономит трафик и время (скачивается только
> последний коммит, без истории git). Если хотите делать доработки и пушить
> обратно — клонируйте без него.

### 3.2 Генерация `.env`

```bash
# Базовый шаблон (Xboard + OlcRTC Integration):
cp .env.olcrtc.example .env

# 3.2.1 APP_KEY — base64-encoded 32 байта (Laravel шифрует сессии, куки и т.д.)
APP_KEY_BASE64="base64:$(head -c 32 /dev/urandom | base64 -w0)"
sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY_BASE64}|" .env

# 3.2.2 OLCRMGR_API_KEY — 64 hex символа (JWT-like shared secret между Xboard и Go-менеджером)
OLCRMGR_KEY="$(openssl rand -hex 32)"
sed -i "s|^OLCRMGR_API_KEY=.*|OLCRMGR_API_KEY=${OLCRMGR_KEY}|" .env

# 3.2.3 APP_URL — публичный URL сайта
# Автоопределяем по ifconfig.me (если VPS за NAT с публичным IP):
PUB_IP="$(curl -s --max-time 5 https://ifconfig.me || echo '127.0.0.1')"
sed -i "s|^APP_URL=.*|APP_URL=http://${PUB_IP}:7001|" .env

# 3.2.4 Создаём нужные директории (композ их создаст сам, но чтобы не было прав root):
mkdir -p .docker/.data storage/logs storage/theme plugins plugins-core
chmod -R u+w .docker/.data storage

# 3.2.5 Посмотрим результат
grep -E '^(APP_KEY|APP_ENV|APP_URL|DB_|REDIS_|OLCRMGR_API_KEY|ADMIN_)' .env
```

---

## 4. Шаг 2 — Первый запуск установщика (миграции, админ, плагины, SPA админки)

Установщик **запускается как разовый** `docker compose run` — НЕ как long-running сервис.
Redis + olcrtc-manager **должны работать** во время установки (Xboard плагин OlcRTC
звонит менеджеру HTTP-API во время миграций/активации).

### 4.1 Поднимаем зависимости (Redis + olcrtc-manager) и ждём healthy

```bash
cd /opt/xboard
docker compose up -d redis olcrtc-manager

# Цикл ожидания (ОБЯЗАТЕЛЬНО):
for i in 1 2 3 4 5 6 7 8 9 10; do
  R_STATE=$(docker inspect -f '{{.State.Health.Status}}' xboard-redis 2>/dev/null || echo 'starting')
  O_STATE=$(docker inspect -f '{{.State.Health.Status}}' xboard-olcrtc-manager 2>/dev/null || echo 'starting')
  echo "  +$((i*5))s redis=${R_STATE}  manager=${O_STATE}"
  [ "$R_STATE" = "healthy" ] && [ "$O_STATE" = "healthy" ] && break
  sleep 5
done
```

### 4.2 Запускаем **headless** установку

```bash
docker compose run --rm \
    -e APP_ENV=production \
    -e AUTO_INSTALL=1 \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e REDIS_HOST=redis \
    -e REDIS_PORT=6379 \
    -e ADMIN_ACCOUNT=admin@example.com \
    -e ADMIN_PASSWORD=Admin123456 \
    xboard php artisan xboard:install --no-interaction
STATUS=$?

echo "xboard:install exit=$STATUS"
```

Ожидаемый вывод при **успехе**:
```
正在导入数据库请稍等...
   INFO  Migrations: X completed.
数据库导入完成
开始注册管理员账号
正在安装默认插件...
   INFO  Nothing to migrate.
默认插件安装完成
管理面板前端已就绪: 12 个 файл
🎉：一切就绪
管理员邮箱：admin@example.com
管理员密码：Admin123456
访问 http(s)://你的站点/<secure_path> 进入管理面板
```

### 4.3 Проверка плагина OlcRTC в БД (ОБЯЗАТЕЛЬНО!)

```bash
sqlite3 ./.docker/.data/xboard.sqlite "
SELECT code, is_enabled, version, name FROM v2_plugins ORDER BY code;
"
```

✅ Успех (**обратите внимание на is_enabled=1** для olc_rtc):
```
alipay_f2f|1|v1.0.0|Alipay 当面付
btcpay|1|v1.0.0|BTCPay Server
coin_payments|1|v1.0.0|CoinPayments
coinbase|1|v1.0.0|Coinbase Commerce
epay|1|v1.0.0|易支付聚合接口
mgate|1|v1.0.0|MGate
olc_rtc|1|v1.0.0|OlcRTC Integration        ← ВАЖНО
telegram|1|v1.0.0|Telegram 机器人通知
```

Если `olc_rtc|0` — явно перевключите через artisan tinker (commit 49fd0db сделает это
автоматически, но на всякий случай):
```bash
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='\App\Services\Plugin\PluginManager::enable(\"olc_rtc\");'"
```

---

## 5. Шаг 3 — Подъём всего стека и health checks

```bash
cd /opt/xboard
docker compose up -d 2>&1 | tail -10
```

Ожидаем 3 сервиса (60–120 секунд прогрева Octane + RoadRunner + Caddy):

```
NAME                    IMAGE                                                    STATUS                 PORTS
xboard-web              ghcr.io/vsvavan2/xboard:latest                           Up (healthy)           0.0.0.0:7001->7001/tcp
xboard-redis            redis:7-alpine                                           Up (healthy)           6379/tcp
xboard-olcrtc-manager   ghcr.io/vsvavan2/xboard-olcrtc-manager:latest           Up (healthy)           8080/tcp
```

**Все 3 = healthy**. Если какой-то `restarting` — смотрите его логи через `docker compose logs`.

### 5.1 Live-проверка HTTP

```bash
# Изнутри сервера:
curl -sS -o /dev/null -w "HTTP /: %{http_code}\n" --max-time 10 http://127.0.0.1:7001/
# Ожидаем 200, 301 или 302 (редирект на /login — это НОРМАЛЬНО)

# Админка (узнать secure_path — commit 0bc7a90):
SEC=$(sqlite3 ./.docker/.data/xboard.sqlite "SELECT value FROM v2_system_config WHERE name='secure_path' LIMIT 1;")
echo "ADMIN: http://${PUB_IP}:7001/${SEC}"
```

---

## 6. Шаг 4 — Создать DNS и HTTPS (по желанию, но рекомендуется)

Для продажи VPN/доступа пользователям ЮKassa требуется **валидный HTTPS-вебхук**.
Ставьте за Nginx/Caddy **на VPS** или Cloudflare Tunnels (самый простой вариант).

### 6.1 Быстрый вариант: Cloudflare Zero Trust Tunnel (без SSL на VPS!)

```bash
# Установите cloudflared и подключите туннель
# HTTPS: vpn.example.com → http://127.0.0.1:7001 (трафик идёт через Cloudflare CDN)
```
Теперь все пользователи заходят по `https://vpn.example.com` (уже SSL/HTTP/3/WAF).

### 6.2 Вариант B — Caddy reverse proxy + Let's Encrypt

Ставим Caddy рядом или в отдельном compose:
```
# Caddyfile (пример)
vpn.example.com {
    reverse_proxy 127.0.0.1:7001
}
```
В `APP_URL` подставьте `https://vpn.example.com` и почистите кэш:
```bash
cd /opt/xboard
docker compose exec xboard php artisan config:cache
```

---

## 7. Полный список переменных `.env`

### 7.1 Общие Laravel

| Переменная      | Пример                                          | Описание                                                              |
|-----------------|-------------------------------------------------|-----------------------------------------------------------------------|
| `APP_NAME`      | `Xboard`                                        | Название сайта (e-mail subject, страницы)                             |
| `APP_ENV`       | `production` / `local` / `testing`              | **production отключает debug-bar и YesNo prompts**                   |
| `APP_KEY`       | `base64:AAAAA...`  (32 байта base64)            | Шифрование сессий/cookie (генерируется install.sh или step 3.2.1)    |
| `APP_DEBUG`     | `true` / `false`                                | **Только `false` на бою!** — иначе утечки env / stack traces         |
| `APP_URL`       | `http://1.2.3.4:7001` / `https://vpn.example.ru`| URL админки и ЛК (пользователи видят его в емейлах/ссылках)          |
| `INSTALLED`     | (пусто) / `true`                                | Команда install устанавливает в `true` после успеха                  |

### 7.2 БД (используется SQLite — без отдельного сервера)

| Переменная         | Пример                            | Описание                                                            |
|--------------------|-----------------------------------|---------------------------------------------------------------------|
| `DB_CONNECTION`    | `sqlite`                          | ✅ **Рекомендуется для форка** (0 обслуживания, 1 файл .docker/.data/xboard.sqlite) |
| `DB_DATABASE`      | `.docker/.data/xboard.sqlite`     | Относительный путь от `/www` в контейнере                           |
| ~~DB_HOSTNAME~~    | *(unused for sqlite)*             |                                                                     |

> Альтернатива для больших инсталляций: `mysql` или `pgsql` (оригинал Xboard поддерживает, но в
> форке не тестировалось с olc_rtc плагином).

### 7.3 Redis (обязательно, нужен Horizon Queue + Octane Table cache)

| Переменная         | Пример                         | Описание                                                          |
|--------------------|--------------------------------|-------------------------------------------------------------------|
| `REDIS_HOST`       | `redis` (compose hostname)     | Если начинается с `/` — **Unix-socket** (embedded Redis). Иначе TCP |
| `REDIS_PORT`       | `6379`                         | TCP-порт (игнорируется для socket)                                |
| `REDIS_PASSWORD`   | *(пусто)*                      | Пароль Redis (если отдельный external кластер)                    |

### 7.4 OlcRTC Plugin + Manager

| Переменная         | Пример (`64 hex`)                                 | Описание                                                                 |
|--------------------|---------------------------------------------------|--------------------------------------------------------------------------|
| `OLCRMGR_API_URL`  | `http://olcrtc-manager:8080`                      | Compose DNS имя менеджера (НЕ торчит наружу, внутренняя сеть)           |
| `OLCRMGR_API_KEY`  | `a01b17c9ff.... (64 символа hex)`                 | JWT HS256 shared-secret между `Xboard ↔ Go-менеджером`                  |
| `OLCRMGR_PROVIDER` | `jitsi`                                           | olcrtc backend: `jitsi` (рекомендуется, без токенов), `mediasoup`       |
| `OLCRMGR_TRANSPORT`| `datachannel`                                     | Релай трафика: `datachannel` (децентрализованное P2P + Fallback TURN)   |
| `OLCRMGR_TRIAL_H`  | `6`                                               | Автотриал новых пользователей (часов)                                  |
| `OLCRMGR_DNS`      | `8.8.8.8:53`                                      | DNS-resolver внутри olcrtc-proc (если пользователи жалуются что сайт не открывается) |

### 7.5 Mail (необязательно — опционально)

| Переменная       | Пример                          | Описание                              |
|------------------|---------------------------------|---------------------------------------|
| `MAIL_MAILER`    | `log` / `smtp` / `ses`          | `log` — письма в storage/logs (для теста) |
| `MAIL_HOST` etc  | как у любого Laravel 11         | *(см. .env.example)*                  |

### 7.6 Headless install (только для `xboard:install` AUTO_INSTALL)

| Переменная        | Пример                  | Описание                                                              |
|-------------------|-------------------------|-----------------------------------------------------------------------|
| `AUTO_INSTALL=1`  | `1`                     | Отключает любые Yes/No prompts                                        |
| `ENABLE_SQLITE=1` | `1`                     | Форсирует sqlite как DB_CONNECTION                                    |
| `ENABLE_REDIS=1`  | `1`                     | Предпочитать Redis и composer-host redis (TCP)                        |
| `ADMIN_ACCOUNT`   | `admin@example.com`     | Email админа при создании                                             |
| `ADMIN_PASSWORD`  | `Admin123456`           | Пароль админа (мин 8 символов)                                        |
| `DB_FORCE_WIPE=1` | *(пусто)* / `1`         | Если БД уже имеет таблицы — очистить перед миграциями                 |

---

## 8. Порты Docker Compose

| Сервис            | Host-порт       | Container port | Назначение                                                            |
|-------------------|-----------------|----------------|-----------------------------------------------------------------------|
| **xboard-web**    | `0.0.0.0:7001`  | `:7001`        | **ЕДИНСТВЕННЫЙ** порт для внешнего мира: Caddy → Octane → Laravel SPA |
| redis             | *(нет)*         | `:6379`        | Только внутренняя сеть compose (НЕ снаружи!)                         |
| olcrtc-manager    | *(нет)*         | `:8080`        | HTTP JSON API для плагина Xboard (НЕ снаружи!)                        |
| user-processes (olcrtc srv)| *(нет)* | динамические UDP/STUN/TURN | Порождаются менеджером, НЕ входят в compose ports (все они bind в NET_ADMIN режиме внутри контейнера) |

> 💡 Порт `7001` можно поменять в `docker-compose.yml` строка ports: `- "80:7001"` (если хотите 80 сразу).

---

## 9. Volumes — где живут данные

| Compose volume / bind-mount | Container path       | Что там хранится                                                            | Бэкапить? |
|------------------------------|----------------------|-----------------------------------------------------------------------------|-----------|
| `.docker/.data/`            | `/www/.docker/.data` | ✅ **sqlite DB xboard.sqlite** (ВСЕ пользователи, платежи, тарифы, плагины) | ✅ ДА 100% |
| `redis-data` (named volume) | `/data` inside redis | Redis очереди Horizon, кэш сессий (восстанавливаем из БД)                  | ⚠️ Можно потерять, не критично |
| `olcrmgr-data`  (named)     | `/data` inside mgr   | OlcRTC manager SQLite: users instances, crypto_keys                        | ✅ ДА — связь с пользователями |
| `./storage/logs`            | `/www/storage/logs`  | Laravel лог ошибок                                                          | Полезно хранить 30 дней |
| `./storage/theme`           | `/www/storage/theme` | Кастомизации шаблонов frontend (если включены)                             | Да, если кастомизировали |
| `./plugins`                 | `/www/plugins`       | Пользовательские плагины (OlcRTC, YooKassa драйвер)                        | ✅ ДА |
| `./plugins-core`            | `/www/plugins-core`  | Встроенные плагины Xboard (Alipay, Telegram...)                            | Да (если модифицировали) |

### Единственный бэкап, который реально нужен каждому утру:

```bash
# (положите в crontab root @daily)
DATE=$(date +%F_%H%M)
cd /opt/xboard
docker compose down redis
tar -czf /root/backup-xboard-${DATE}.tar.gz ./.docker/.data ./.env \
      ./plugins ./storage/theme
docker compose up -d redis
# Храните минимум 7 бэкапов в S3 / Backblaze B2 / локально на другом диске
```

---

## ✅ Установка завершена

Откройте **[docs/OLCRTC_QUICKSTART.md](OLCRTC_QUICKSTART.md)** — там полностью описаны:
- Шаг А: настройка плагина OlcRTC (URL + API Key + Provider + Transport)
- Шаг Б: ЮKassa: shop_id, secret_key, capture, вебхук 54-ФЗ
- Шаг В: тарифы VPN (OlcRTC Basic / Pro / Trial)
- Шаг Г: проверка пользовательского сценария end-to-end
