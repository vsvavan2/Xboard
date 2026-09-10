# Xboard × OlcRTC VPN — one-click форк `vsvavan2/Xboard`

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![Go](https://img.shields.io/badge/Go-1.22+-00ADD8.svg)
![Docker](https://img.shields.io/badge/Docker-Compose-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Готовая self-hosted VPN-панель с биллингом через ЮKassa (ПСБ / ЮMoney / СБП / карты)**

</div>

---

## 🏗️ Что это за форк и зачем он нужен

Оригинальный [cedar2025/Xboard](https://github.com/cedar2025/Xboard) — это биллинг-панель для прокси/VPN. В **моём форке** из коробки работает **полный цикл продажи OlcRTC VPN** через веб-сайт (без Telegram-ботов):

| Что есть из коробки | Как реализовано |
|---|---|
| 🧑‍💻 Регистрация пользователя | email + пароль в веб-интерфейсе |
| 🎁 **Автотриал 6 часов** | Сразу после регистрации плагин OlcRTC вызывает Go-менеджер и создаёт olcrtc-инстанс |
| 💳 Оплата подписки | Плагин драйвер **ЮKassa (YooKassa)**: ПСБ через СБП, ЮMoney, Сбер, Тинькофф, карты МИР/Visa/MC, чеки 54-ФЗ |
| 🛰️ OlcRTC-инстанс **на каждого пользователя** | Отдельный бинарный процесс `olcrtc mode=srv` с уникальным `room_id` и 64-значным `crypto_key` |
| 🔗 Выдача ссылок в **Личном кабинете** | `olcrtc://` URI, `client.yaml` (скачать), `sub.md` (ссылка на автообновляемую подписку для OwenClave / OlcBox / Veil) |
| 👮 Автоостановка истёкших подписок | Go-менеджер каждые 30с сверяет `expires_at` + крон Xboard каждые 15 минут |
| ♻️ Автоперезапуск упавших процессов | Менеджер каждые 30с сам поднимает инстанс заново |
| 🐳 **One-click Docker Compose** | Один `curl \| bash` поднимает: Xboard (веб+Octane+Horizon+Caddy+Redis) + olcrtc-manager (Go) + volumes |
| 🤖 CI/CD GitHub Actions | При пуше в `master` собираются **два мультиарх-образа** (amd64 + arm64) → `ghcr.io/vsvavan2/xboard` и `ghcr.io/vsvavan2/xboard-olcrtc-manager` |

---

## 🏛️ Архитектура одним взглядом

```
                        ┌────────────────────────────────────────────────────┐
                        │           Docker Compose — один stack               │
                        │                                                    │
🌐  Internet  :7001 ───►  xboard  (Caddy → Octane PHP → Laravel 12 / Vue3) │
                        │       │                                             │
                        │       │  Internal Docker DNS  HTTP JSON (:8080)    │
                        │       ▼                                             │
                        │  olcrtc-manager  (Go + Gin + SQLite)                │
                        │       │  fork_exec + setsid                         │
                        │       ├─► olcrtc user #1 (srv mode, unique room)  │
                        │       ├─► olcrtc user #2 (srv mode, unique room)  │
                        │       └─► olcrtc user #3 ……                         │
                        └────────────────────────────────────────────────────┘
```

Внешний мир видит только Xboard (Caddy) на `:7001`. OlcRTC-менеджер живёт в внутренней сети и никогда не экспоузится наружу.

---

## ⚡️ Быстрый старт (одна команда на свежий VPS)

Поддерживаемые системы: **Ubuntu 22.04 / 24.04, Debian 12** (под root):

```bash
curl -fsSL https://raw.githubusercontent.com/vsvavan2/Xboard/master/install.sh | bash
```

Скрипт **сам делает всё**:
1. ✅ Устанавливает Docker Engine + docker compose plugin (через официальный get.docker.com)
2. ✅ Клонирует `vsvavan2/Xboard` → `/opt/xboard`
3. ✅ Генерирует `.env`: `APP_KEY`, `OLCRMGR_API_KEY` (64 hex), подставляет публичный IP в `APP_URL`
4. ✅ **АВТОМАТИЧЕСКИ (AUTO_INSTALL=1, без вопросов)** запускает `php artisan xboard:install` — миграции, админ `admin@example.com / Admin123456`, плагины по умолчанию, React-админка
5. ✅ `docker compose up -d` — поднимает весь стек
6. ✅ Нормализует `.env`: `DB_CONNECTION=sqlite`, `DB_DATABASE=.docker/.data/xboard.sqlite`, `REDIS_HOST=redis` (TCP compose, НЕ unix socket!)

После завершения:
```
🌐 Сайт/ЛК пользователя: http://<ПУБЛИЧНЫЙ-IP-VPS>:7001
🛠️ URL админ-панели:    http://<ПУБЛИЧНЫЙ-IP-VPS>:7001/<АВТО-СГЕНЕРИРОВАННЫЙ-ХЭШ>
👤 Логин/пароль админа: admin@example.com / Admin123456
📁 Данные:              /opt/xboard
🔑 API key:             grep OLCRMGR_API_KEY /opt/xboard/.env
```

> 💡 **Как узнать ТОЧНЫЙ URL админки после установки**:
> ```bash
> cd /opt/xboard
> # Способ 1 — из sqlite (самый надёжный — commit 0bc7a90+):
> apt-get install -y sqlite3 2>/dev/null
> SECURE=$(sqlite3 ./.docker/.data/xboard.sqlite "SELECT value FROM v2_system_config WHERE name='secure_path' LIMIT 1;")
> echo "Админка: http://$(curl -s ifconfig.me):7001/${SECURE}"
>
> # Способ 2 — через tinker (дольше, но всегда работает):
> docker compose exec xboard php artisan tinker --execute="
>   \$key = hash('crc32b', config('app.key'));
>   echo 'Админка: ' . rtrim(config('app.url'), '/') . '/' . admin_setting('secure_path', admin_setting('frontend_admin_path', \$key)) . PHP_EOL;
> "
> ```

---

## 📚 Сопутствующая документация (в репозитории)

| Документ                                     | О чём                                                                                       |
|----------------------------------------------|---------------------------------------------------------------------------------------------|
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)    | **Все 15 известных багов** с диагностикой, root-cause и copy-paste решениями               |
| [docs/OLCRTC_QUICKSTART.md](docs/OLCRTC_QUICKSTART.md)| **Полная настройка OlcRTC**: URL менеджера, API-key, ЮKassa вебхук, тарифы, проверка выдачи ссылок |
| [docs/INSTALL.md](docs/INSTALL.md)                    | Ручная пошаговая установка (**без** `curl \| bash`) — полный список env, портов, volumes   |
| [README-OlcRTC-SETUP.md](README-OlcRTC-SETUP.md)      | Альтернативная инструкция: как опубликовать этот форк на свой GHCR + one-click VPS         |
| [docs/en/installation/docker-compose.md](docs/en/installation/docker-compose.md) | Оригинальная англ. инструкция по generic docker-compose установке (апстрим cedar2025/Xboard) |
| [docs/en/development/plugin-development-guide.md](docs/en/development/plugin-development-guide.md) | Как писать свои плагины для Xboard (hook-система, AbstractPlugin)                           |

---

## 🧩 Админ-панель: откуда она берётся и почему не бывает «белой страницы»

Кабинет администратора — это отдельный **React/Vite SPA**, который живёт **не в этом репозитории**, а подтягивается как Git-submodule `public/assets/admin` из
[`cedar2025/xboard-admin-dist`](https://github.com/cedar2025/xboard-admin-dist) (уже собранный production-бандл).

### Три уровня гарантии, что админка всегда есть:
| Уровень            | Когда срабатывает | Что делает |
|--------------------|-------------------|------------|
| **1. Dockerfile**  | Во время `docker build` / сборки GHCR-образа | Клонирует `xboard-admin-dist` → `/www/public/assets/admin` |
| **2. entrypoint.sh** | При каждом `docker compose up -d` контейнера | Проверяет `/www/public/assets/admin/manifest.json` и если нет/битый — клонирует заново |
| **3. xboard:install** | При ручной не-Docker установке | Команда установки сама делает `git clone xboard-admin-dist` в `public/assets/admin` |

Если вы хотите **запретить entrypoint перекачивать админку каждый раз** и сохранить свою сборку — раскомментируйте volume в `docker-compose.yml` (см. комментарий «Persistent admin SPA»).

---

## 🛠️ Пошаренная (ручная) установка

Если не хочешь one-click скрипт:

```bash
# 1. Клонируем
git clone --depth 1 https://github.com/vsvavan2/Xboard /opt/xboard && cd /opt/xboard

# 2. Генерируем .env
cp .env.olcrtc.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(head -c 32 /dev/urandom | base64 -w0)|" .env
sed -i "s|^OLCRMGR_API_KEY=.*|OLCRMGR_API_KEY=$(openssl rand -hex 32)|" .env

# 3. Первичная установка БД и админа
mkdir -p .docker/.data storage/logs storage/theme plugins
docker compose run -it --rm -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
    xboard php artisan xboard:install

# 4. Поднимаем всё и оставляем работать
docker compose up -d
```

---

## 🤖 Headless (ENV-driven) установка `xboard:install`

Команда `php artisan xboard:install` работает **в двух режимах**: интерактивном (по умолчанию, с вопросами) и **полностью автоматическом** — когда задана переменная `AUTO_INSTALL=1`. Это используется в `install.sh` и CI/CD.

Полный список ENV-переменных для headless-режима:

| Переменная          | Значение по умолчанию               | Описание                                                                 |
|---------------------|-------------------------------------|--------------------------------------------------------------------------|
| `AUTO_INSTALL=1`    | —                                   | **Главный флаг**: отключает все prompts (select/confirm/text).           |
| `ENABLE_SQLITE=true`| —                                   | Принудительно выбрать `DB_CONNECTION=sqlite`.                            |
| `DB_TYPE`           | `sqlite` (если `ENABLE_SQLITE`)     | `sqlite` / `mysql` / `postgresql`.                                       |
| `ENABLE_REDIS=true` | —                                   | Предпочитать Redis; в Docker = default-host `redis` (compose service).   |
| `REDIS_HOST`        | `redis` (Docker+ENABLE_REDIS) иначе `127.0.0.1` | **Если начинается с `/`** — Unix-socket (embedded Redis внутри контейнера). **Иначе** — TCP hostname (`redis`, `192.168.x.x`, `redis.example.com`). |
| `REDIS_PORT`        | `6379`                              | TCP-порт Redis (игнорируется при socket).                                |
| `REDIS_PASSWORD`    | `""`                                | Пароль TCP Redis.                                                        |
| `ADMIN_ACCOUNT`     | `admin@example.com`                 | Email администратора, валидируется как email.                            |
| `ADMIN_PASSWORD`    | auto-generated `Helper::guid(false)`| Явный пароль админа (мин. 8 символов).                                   |
| `DB_FORCE_WIPE=1`   | `false`                             | Если в базе уже есть таблицы — автоматически очистить (`db:wipe --force`) вместо выхода. |
| `INSTALLED=true`    | —                                   | (флаг уже установленной установки — команда пропустит выполнение).       |

### Пример ручного headless запуска:
```bash
cd /opt/xboard
docker compose run --rm \
    -e AUTO_INSTALL=1 \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e REDIS_HOST=redis \
    -e ADMIN_ACCOUNT=admin@tvoy.ru \
    -e ADMIN_PASSWORD=MyStrongPass123 \
    xboard php artisan xboard:install
```

---

## ⚙️ Настройка после первого запуска (3 минуты в админке)

Заходишь в админку `http://IP:7001` под созданным админом:

### 🔌 1. Включить плагин OlcRTC
`Плагины → OlcRTC Integration → ⚙️ Настроить`

| Поле                  | Значение (для docker-compose)        |
|-----------------------|-------------------------------------|
| URL olcrtc-manager    | `http://olcrtc-manager:8080`        |
| API-ключ olcrtc-manager | `grep OLCRMGR_API_KEY /opt/xboard/.env` |
| Провайдер по умолчанию | `jitsi` (рекомендуется, не требует токена) |
| Транспорт             | `datachannel`                       |
| DNS                   | `8.8.8.8:53` или `1.1.1.1:53`       |
| Длительность триала (ч) | `6`                               |
| Включить тестовый период | **☑️ да**                           |

`Сохранить` → переключи слайдер **Включен** в позицию вкл.

### 💳 2. Подключить платёжку ЮKassa
`Платежы → Добавить платёжный метод → ЮKassa (YooKassa)`

| Поле               | Где взять                              |
|--------------------|-----------------------------------------|
| Shop ID            | ЛК ЮKassa → Настройки → Магазин → ID   |
| Секретный ключ     | ЛК ЮKassa → Настройки → API-ключи      |
| Методы оплаты      | `bank_card,sberbank,yoomoney,sbp,tinkoff_bank` |
| Автоматический capture | `☑️ да` (сразу списывать деньги)    |
| Отправлять чек 54-ФЗ | только если подключена онлайн-касса |

В ЛК ЮKassa укажи **URL вебхука** (HTTP-уведомления):
```
https://твой.домен/api/v1/guest/payment/notify/yookassa
```

### 💼 3. Создать тариф
`Тарифы → Добавить план`:
- Название: **Месяц OlcRTC VPN**
- Цена: **29900** (= 299 ₽, всегда в **копейках** — рубли ×100)
- Период: `monthly` / `quarterly` / `yearly` / на свой выбор
- Слайдер **Включен** → вкл.

Готово! Теперь пользователи регистрируются → получают 6ч бесплатно → покупают подписку → сразу в ЛК видят продлённую olcrtc:// ссылку.

---

## 👥 Как выглядит у пользователя (то, что ты хотел)

1. `https://твой.домен` → **Регистрация** (email + пароль)
2. Личный кабинет:
   ```
   GET /api/v1/user/olcrtc           → JSON: uri, yaml, subscribe_url, expires_at, is_active
   GET /api/v1/user/olcrtc/yaml      → скачать client.yaml (Attachment)
   GET /api/v1/user/olcrtc/sub?token=<user.token> → plain text sub.md
   ```
3. Сразу после регистрации **сам включается 6-часовой триал** (зависит от настроек плагина)
4. Истекает → Кнопка **💳 Купить** → выбор метода оплаты → редирект на ЮKassa → оплата → возврат на сайт
5. Сразу после успешного вебхука ЮKassa → плагин вызывает olcrtc-manager → `expires_at` пользователя продлён → в ЛК висит уже продлённый инстанс.

**Никаких Telegram-ботов, ручной выдачи ключей и перезапусков сервисов** — всё автоматически.

---

## 🧰 Команды админа VPS

```bash
cd /opt/xboard

# Статус
docker compose ps

# Логи веб-панели (Laravel/Octane/Caddy)
docker compose logs xboard --tail=100 -f

# Логи VPN-менеджера
docker compose logs olcrtc-manager --tail=100 -f

# Логи конкретного olcrtc-процесса пользователя (внутри контейнера)
docker exec xboard-olcrtc-manager ls -la /var/lib/olcrtc-manager/instances/
docker exec xboard-olcrtc-manager cat   /var/lib/olcrtc-manager/instances/<ID>.log

# Перезапуск всего стека
docker compose restart

# Обновление до последней версии
cd /opt/xboard && git pull && docker compose pull && docker compose up -d

# Создать админа вручную
docker compose run -it --rm xboard php artisan xboard:reset-password admin@tvoy.ru MyNewPass123
```

---

## 📦 Что внутри репозитория

| Путь                             | Описание                                                          |
|----------------------------------|-------------------------------------------------------------------|
| `Dockerfile`                     | Сборка веб-образа: PHP 8.2 + Swoole + Octane + Caddy + плагин OlcRTC **вместе с кодом из этого репозитория** |
| `olcrtc-manager.Dockerfile`      | Сборка Go-менеджера + автоматическая подкачка бинарника `olcrtc` с GitHub Releases `openlibrecommunity/olcrtc` |
| `docker-compose.yml`             | **Единый one-click стек**: xboard + olcrtc-manager + volumes (`redis-data`, `olcrmgr-data`) + docker DNS |
| `.env.olcrtc.example`            | Шаблон .env с уже преднастроенными `OLCRMGR_*` переменными         |
| `install.sh`                     | One-Click `curl \| bash` скрипт установки на VPS                  |
| `README-OlcRTC-SETUP.md`         | Расширенная инструкция + готовый **Vue/Inertia компонент** для красивой OlcRTC-вкладки в ЛК |
| `.github/workflows/docker-publish.yml` | GitHub Actions: при пуше в master собираются **два мультиарх-образа** (amd64 + arm64) и пушатся в GHCR |
| `plugins/OlcRTC/Plugin.php`      | Плагин Xboard: хуки `user.register.after` + `order.open.after` + крон синхронизации |
| `plugins/OlcRTC/Payments/YooKassaPayment.php` | Драйвер ЮKassa: создание платежей, вебхуки, автокапча, чеки 54-ФЗ |
| `plugins/OlcRTC/Controllers/OlcRTCController.php` | User API: `/api/v1/user/olcrtc`, `/yaml`, `/sub?token=` |
| `olcrtc-manager/`                | Go-микросервис: REST API (Gin) + SQLite + супервизор процессов (один `olcrtc srv` → один пользователь) |

---

## 🎨 Опционально: красивый Vue-компонент «OlcRTC» в ЛК

В [README-OlcRTC-SETUP.md → секция 🎨](./README-OlcRTC-SETUP.md) лежит полностью готовый `<script setup>` + `<template>` компонент для стандартной темы Xboard (Vue3 + Inertia + Tailwind):

- 🔗 Блок с olcrtc:// URI + зелёная кнопка **«📋 Копировать»** (clipboard API + индикатор ✓)
- 📄 Кнопка **«⬇️ Скачать client.yaml»**
- 📡 Ссылка на автообновляемую подписку (sub.md)
- 🟢 Бейдж статуса: «Подписка активна» / «Истекла» / «Заблокирован»
- ⏳ Прогресс: сколько часов подписки осталось
- 💳 CTA-баннер «Продлить подписку от 299 ₽» — когда до конца < 72ч

Как внедрить — описано там же, шаг за шагом.

---

## ❓ Частые проблемы

**1. Белая страница при открытии URL админки `/<хэш>`**
Причина: в образе/контейнере нет собранного React-бандла админки (`/www/public/assets/admin/manifest.json`).
Исправление — достаточно **перезапустить контейнер** (вход в него есть entrypoint, который сам подтянет бандл):
```bash
cd /opt/xboard
docker compose restart xboard
sleep 40
# Проверка что всё появилось — должен вернуть 200:
curl -s -o /dev/null -w 'HTTP=%{http_code}\n' http://127.0.0.1:7001/assets/admin/manifest.json
```
Если не помогло — клонируйте вручную:
```bash
docker compose exec -T xboard sh -c '
  rm -rf /www/public/assets/admin
  git clone --depth=1 https://github.com/cedar2025/xboard-admin-dist.git /www/public/assets/admin
  rm -rf /www/public/assets/admin/.git'
```

**2. Ошибка 403 / Unauthorized в админке (логин верный, но не пускает)**
Причина: у пользователя в таблице `users` стоит `is_admin=0` (нет прав администратора).
Исправление:
```bash
cd /opt/xboard
docker compose exec xboard php artisan tinker
# В интерактивной консоли:
  $u = \App\Models\User::byEmail('admin@tvoy.ru')->first();
  $u->is_admin = 1; $u->is_staff = 1; $u->banned = 0;
  $u->save(); echo "OK: is_admin={$u->is_admin}\n"; exit;
```

**3. URL админки неизвестен / забыл**
Путь админки НЕ `/admin` — он вычисляется как `crc32b(APP_KEY)` или переопределяется через настройку `secure_path`.
Узнать точный URL:
```bash
cd /opt/xboard
docker compose exec xboard php -r '
  $key = hash("crc32b", env("APP_KEY"));
  $env_url = rtrim(env("APP_URL"), "/");
  $secure = env("ADMIN_SECURE_PATH");
  $path = $secure ?: $key;
  echo "Админка: $env_url/$path\n";
'
```

**4. Ошибка `/usr/local/bin/olcrtc: not found` в менеджере**
Если GitHub Releases openlibrecommunity/olcrtc в момент сборки образа не вернул бинарник, менеджер положит плейсхолдер.
Исправление:
- Собери `olcrtc` из исходников на хосте → положи `/usr/local/bin/olcrtc`
- В `docker-compose.yml` раскомментируй volume:
  ```yaml
  services:
    olcrtc-manager:
      volumes:
        - /usr/local/bin/olcrtc:/usr/local/bin/olcrtc:ro
  ```
- `docker compose up -d --force-recreate olcrtc-manager`

**5. `/dev/net/tun` отсутствует в контейнере**
- Хост: `modprobe tun` и проверь `ls /dev/net/tun`
- В `docker-compose.yml` сервис `olcrtc-manager` уже содержит `devices: [/dev/net/tun]` и `cap_add: [NET_ADMIN, NET_RAW]` — убедись что они не закомментированы.

**6. Плагин не создаёт триал после регистрации**
- Проверь: слайдер плагина вкл, `trial_enabled: true`, `trial_hours > 0`
- `docker compose logs olcrtc-manager | grep trial`
- Убедись, что `manager_url` в плагине — `http://olcrtc-manager:8080` (а не 127.0.0.1:8080, он внутри контейнера недоступен!)

**7. ЮKassa вебхук не приходит**
- Укажи настоящий `APP_URL=https://твой.домен` (не localhost)
- Включи сначала «Тестовый режим» в ЛК ЮKassa → `shop_id=548791` → тестовые карты.

**8. Контейнер xboard падает, в логах `reaped unknown pid XXX (terminated by SIGKILL)`**
Причина: OOM Killer в ядре убивает Octane/Horizon из-за нехватки ОЗУ. Обычно сопровождается `Swap usage > 90%`.
Исправление — увеличьте swap до 2GB и подчистите docker-образы:
```bash
swapoff /swap.img 2>/dev/null
dd if=/dev/zero of=/swap.img bs=1M count=2048 status=progress
chmod 600 /swap.img
mkswap /swap.img
swapon /swap.img
grep -q '/swap.img' /etc/fstab || echo '/swap.img none swap sw 0 0' >> /etc/fstab
free -h
# Очистка мусора Docker:
docker system prune -af
```
Также уменьшите профиль ресурсов в `docker-compose.yml` → `RESOURCE_PROFILE=minimal`.

**9. Плагин ЮKassa не появляется в списке платёжных методов в админке**
Причина: у плагина `plugins/OlcRTC/config.json` отсутствует обязательное поле `"type": "payment"` (PluginManager разрешает только `feature` или `payment`; без явного `type` по умолчанию `feature` → `getEnabledPaymentPlugins()` его не возвращает).
Исправление: **уже включено в этом релизе** (см. `plugins/OlcRTC/config.json:5`). Если вы используете старый config.json:
```json
{
  "name": "OlcRTC Integration",
  "code": "olc_rtc",
  "version": "1.0.0",
  "type": "payment",   // ← ДОБАВИТЬ ЭТУ СТРОКУ
  "..."
}
```
После правки → админка → Плагины → Обновить или переустановить плагин.

**A1. Флаг `olcrtc_enable` не приходит в `GET /api/v1/user/comm/config` → OlcRTC-вкладка не отображается в ЛК**
Причина: контроллер пользовательского конфига `V1\User\CommController` не вызывал фильтр `user_comm_config` (в отличие от гостевого `Guest\CommController`, где фильтр `guest_comm_config` работал). Плагин OlcRTC подписан именно на `user_comm_config` → хук никогда не срабатывал.
Исправление: **уже включено в этом релизе** — [CommController.php:28](file:///C:/Users/Администратор.DESKTOP-R6LJ89I/Downloads/Xboard-src/app/Http/Controllers/V1/User/CommController.php#L28). Проверка:
```bash
curl -s -H 'Authorization: Bearer <user-token>' http://IP:7001/api/v1/user/comm/config | jq '.data.olcrtc_enable'
# → true (если плагин включён)
```

**A2. Плагин включён, но контроллер `/api/v1/user/olcrtc` всегда возвращает «Плагин OlcRTC не включён» (acronym-bug)**
Причина: `HasPluginConfig::convertToKebabCase('OlcRTC')` → regex `([a-z])([A-Z])` разбивает каждую соседнюю пару `a-z/A-Z` → `Olc_R_T_C` → lowercase → `olc_r_t_c`, а в БД у плагина `code='olc_rtc'`. `Plugin::where('code','olc_r_t_c')` не находит → `isPluginEnabled()` возвращает `false`.
Исправление: **уже включено в этом релизе** — метод `convertToKebabCase` теперь сверяет каноническое представление (strip всех `_/-/ ` + lowercase) против всех известных plugin codes из БД и возвращает ТОЧНЫЙ код как он лежит в БД. Ручная проверка:
```sql
SELECT code FROM v2_plugins; -- ожидаем: olc_rtc (НЕ olc_r_t_c!)
```

**A3. Docker compose + xboard:install → контейнер xboard падает с `RedisException: Connection refused`**
Причина: в `XboardInstall.php` раньше при `Docker + ENABLE_REDIS=true` безусловно проставлялся `REDIS_HOST=/data/redis.sock` (Unix socket), но в стандартном `docker-compose.yml` Redis живёт **отдельным TCP-сервисом** `redis:6379` (socket не существует!).
Исправление: **уже включено в этом релизе** — XboardInstall:
  1. Если `REDIS_HOST` уже передан как ENV и **НЕ** начинается с `/` → используем как TCP hostname.
  2. Если `REDIS_HOST` начинается с `/` → Unix socket (embedded Redis внутри контейнера xboard).
  3. В `install.sh` явно передаётся `-e REDIS_HOST=redis -e REDIS_PORT=6379` — correct TCP compose-service.
Проверка после установки:
```bash
cd /opt/xboard && grep -E '^REDIS_' .env
# → REDIS_HOST=redis, REDIS_PORT=6379 (НЕ /data/redis.sock!)
```

**A4. install.sh раньше падал с ошибкой «Command xboard:admin is not defined»**
Причина: в проекте artisan-команды `xboard:admin create email password` **никогда не существовало**. Есть только `reset:password email [password]` и интерактивная `xboard:install`.
Исправление: **уже включено в этом релизе** — `install.sh` теперь вызывает ЕДИНСТВЕННУЮ команду с headless-flags:
```bash
docker compose run --rm \
  -e AUTO_INSTALL=1 -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
  -e REDIS_HOST=redis -e REDIS_PORT=6379 \
  -e ADMIN_ACCOUNT=admin@example.com -e ADMIN_PASSWORD=Admin123456 \
  xboard php artisan xboard:install
```
Она же сама делает migrate, register admin, install default plugins, clone admin SPA.

**A5. composer install / docker build предупреждение: «Class directory Library does not exist»**
Причина: в `composer.json` объявлен PSR-4 `"Library\\": "library/"`, а папки `library/` в репозитории не было.
Исправление: **уже включено в этом релизе** — создан `library/.gitkeep` (пустая папка с маркером, чтобы Git её сохранил). Если у вас старая версия — достаточно `mkdir -p library && touch library/.gitkeep`.

---

## 🛠️ Технологический стек

| Слой              | Технологии                                                       |
|-------------------|------------------------------------------------------------------|
| Бэкенд            | **PHP 8.2, Laravel 12, Octane (Swoole), Horizon**               |
| Админ-панель      | **React, Shadcn UI, TailwindCSS**                                |
| Пользовательский ЛК | **Vue 3, TypeScript, NaiveUI** (стандартная тема Xboard)      |
| VPN-менеджер      | **Go 1.22, Gin (REST), modernc.org/sqlite, UUID, YAML**          |
| Деплой            | **Docker, docker-compose, мультиарх-образы amd64 + arm64**      |
| Оплата            | **ЮKassa (YooKassa) API v3** — ПСБ / ЮMoney / СБП / карты      |
| Кеширование       | **Redis + Octane cache**                                         |

---

## ⚠️ Отказ от ответственности

Данный форк предназначен **исключительно для обучения и собственного использования**. Всегда соблюдайте законодательство вашей юрисдикции относительно организации доступа к сетям. Автор не несёт ответственности за любые последствия использования этого ПО.

---

## 🔔 Важные примечания

1. После изменения **пути админ-панели** в настройках — перезапусти:
   ```bash
   cd /opt/xboard && docker compose restart
   ```
2. Все данные хранятся в Docker volumes: `redis-data`, `olcrmgr-data`, а также в примонтированных каталогах `.docker/.data`, `storage/`, `plugins/`. Не забывай делать бэкапы этих директорий.
3. Для `aaPanel` или `1Panel` — можешь использовать исходные compose-файлы из оригинальной документации (лежат в `docs/en/installation/`), дополнив их сервисом `olcrtc-manager` из нашего [docker-compose.yml](./docker-compose.yml).

---

## 🤝 Спасибо оригинальному проекту

Этот форк построен на основе open-source проекта [cedar2025/Xboard](https://github.com/cedar2025/Xboard) под лицензией MIT. Огромное спасибо cedar2025 за такую мощную панель! 💙

Баги и PR — приветствуются в Issues форка.
