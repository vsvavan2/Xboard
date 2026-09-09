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
4. ✅ Интерактивно запускает `php artisan xboard:install` — ты вводишь email/пароль админа
5. ✅ `docker compose up -d` — поднимает весь стек

После завершения:
```
🌐 Панель:   http://<ПУБЛИЧНЫЙ-IP-VPS>:7001
📁 Данные:   /opt/xboard
🔑 API key:  grep OLCRMGR_API_KEY /opt/xboard/.env
```

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

**1. Ошибка `/usr/local/bin/olcrtc: not found` в менеджере**
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

**2. `/dev/net/tun` отсутствует в контейнере**
- Хост: `modprobe tun` и проверь `ls /dev/net/tun`
- В `docker-compose.yml` сервис `olcrtc-manager` уже содержит `devices: [/dev/net/tun]` и `cap_add: [NET_ADMIN, NET_RAW]` — убедись что они не закомментированы.

**3. Плагин не создаёт триал после регистрации**
- Проверь: слайдер плагина вкл, `trial_enabled: true`, `trial_hours > 0`
- `docker compose logs olcrtc-manager | grep trial`
- Убедись, что `manager_url` в плагине — `http://olcrtc-manager:8080` (а не 127.0.0.1:8080, он внутри контейнера недоступен!)

**4. ЮKassa вебхук не приходит**
- Укажи настоящий `APP_URL=https://твой.домен` (не localhost)
- Включи сначала «Тестовый режим» в ЛК ЮKassa → `shop_id=548791` → тестовые карты.

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
