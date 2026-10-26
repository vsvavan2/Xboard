# Mobi VPN · Xboard OlcRTC-only (QUICKSTART)
> ⚡ Одностраничная инструкция для VPS headless-развёртывания. ЮKassa / ЮMoney / OlcRTC Widget / Matrix-green UI из коробки.

## 0. Требования VPS
- Ubuntu 22.04 / 24.04 LTS
- CPU ≥ 1 vCPU · RAM ≥ 1 GB · Disk ≥ 15 GB SSD
- Открытые порты: 80, 7001 (inbound TCP)
- Доступ root по SSH
- Docker ≥ 29 + Docker Compose v5 (установщик сам поставит, если их нет)

---

## 1. 🚀 Развёртывание «в одну команду» (headless VPS)
Подключись по SSH к чистому VPS → выполни (копируй целиком):

```bash
apt update -y && apt install -y git curl sudo && rm -rf /opt/xboard && git clone https://github.com/vsvavan2/Xboard.git /opt/xboard && cd /opt/xboard && chmod +x install.sh && sudo -E bash install.sh
```

Скрипт сам:
- Установит Docker / Compose (если отсутствуют)
- Сгенерирует `APP_KEY` / `OLCRMGR_API_KEY` (64 hex)
- Пересоберёт xboard-web локально с патчами (русификация админки, OlcRTC-only UI)
- Запустит 3 контейнера: `xboard-redis` · `xboard-olcrtc-manager` · `xboard-web`
- Выполнит AUTO-SEED (3 тарифа, 6 статей БЗ, ЮKassa demo, Matrix-тема)
- Выведет финальные URL для входа

### 📌 Данные по умолчанию (СМЕНИТЕ ПАРОЛЬ СРАЗУ!)
| Параметр | Значение |
|---|---|
| Админ email | `admin@example.com` |
| Админ пароль | `Admin123456` |
| Админка URL | `http://<ВАШ_IP>/<secure_path>` (secure_path выводит установщик в ЗЕЛЁНОЙ РАМКЕ) |
| Главная (без порта!) | `http://<ВАШ_IP>/` |
| Вход в кабинет | `http://<ВАШ_IP>/#/user/login` |

---

## 2. 🔄 Как обновить панель до последней версии (обновление «на месте»)

> ⚠️ **НЕ запускайте `install.sh` повторно на рабочей панели!** Он рассчитан на ЧИСТЫЙ сервер и
> при повторном запуске пересоздаёт БД и настройки (потеря пользователей/заказов/платежей).
> Для обновления используйте команды ниже.
> ✅ **Все ваши данные сохраняются**: `.env`, SQLite DB, пользователи, заказы, настройки платежей.

Скопируй и выполни на VPS одной вставкой:

```bash
cd /opt/xboard \
 && sudo cp -a .env .env.BACKUP-$(date +%Y%m%d_%H%M) \
 && git fetch origin \
 && git reset --hard origin/master \
 && docker compose build olcrtc-manager xboard \
 && docker compose up -d \
 && docker compose exec xboard php artisan optimize:clear
```

### 📌 Нюансы обновления (частые «грабли»)

| Нюанс | Обязательное действие |
|---|---|
| Правили `theme/**/dashboard.blade.php` (виджет ЛК) или `routes/web.php` | После `up -d` **всегда** `docker compose exec xboard php artisan optimize:clear` |
| Правили Go-менеджер (`olcrtc-manager/`) | Обязательно `docker compose build olcrtc-manager` — иначе останется старый бинарник |
| Меняли `.env` (например `OLCRMGR_JITSI_SERVER`) | `docker compose up -d --force-recreate olcrtc-manager` |
| `502` / пустая страница сразу после апдейта | Подожди 60–90 сек — Octane + Caddy прогреваются |
| `git reset --hard` сбрасывает локальные правки | Держи свои изменения в ветке/бэкапе |
| `Could not resolve host: ghcr.io` при `pull` | Собрать локально: `docker compose build xboard olcrtc-manager` (как в команде выше) |
| Ключ выдан, но клиент **«не подключается»** | См. **§8** ниже + [docs/TROUBLESHOOTING.md → T20](docs/TROUBLESHOOTING.md) |
| Сборка падает: `Remote branch latest not found` / curl 404 | `OLCRTC_RELEASE=master` в `.env` + [docs/TROUBLESHOOTING.md → T21](docs/TROUBLESHOOTING.md) |
| Нужно обновить **ядро olcrtc** (апстрим `openlibrecommunity/olcrtc`) | `sh update-olcrtc.sh` — см. **§2.1**. Обычный `build` НЕ сбрасывает кэш ядра |

### 2.1 🧬 Обновление «ядра» olcrtc (`openlibrecommunity/olcrtc`)

> Ключи `olcrtc://…` выдаёт бинарник **olcrtc**, который вшит **внутрь** Docker-образа `olcrtc-manager`
> (`olcrtc-manager.Dockerfile`). У апстрима `openlibrecommunity/olcrtc` **нет GitHub-релизов** (только ветка
> `master` и тег `v0.0.1`), поэтому «обновить ядро» = **пересобрать образ**. Обычный `docker compose build`
> переиспользует закэшированный слой и **не подтянет** новый код — именно для этого есть отдельный скрипт.

```bash
cd /opt/xboard

# 1) Проверить, есть ли обновление ядра (код выхода 10 = есть новое)
sh update-olcrtc.sh --check

# 2) Что установлено сейчас и что лежит в апстриме
sh update-olcrtc.sh --status

# 3) ОБНОВИТЬ (одна команда): узнаёт SHA последнего коммита и пересобирает ядро
sh update-olcrtc.sh

# 4) Включить автообновление (раз в неделю, вс 04:30) — «всё всегда работает» без вашего участия
sh update-olcrtc.sh --install-cron
```

Прочие флаги: `--force` (пересобрать принудительно), `--ref v0.0.1` (конкретный тег/ветка/SHA),
`--prune` (очистить кэш сборки — для VDS с малым диском), `--uninstall-cron`.

✅ **Ключи клиентов (`olcrtc://…`) после обновления остаются прежними** — переустанавливать клиентам
ничего не нужно, меняется только серверное ядро.

Проверить установленную версию ядра:
```bash
docker compose exec olcrtc-manager cat /etc/olcrtc.version
curl -s http://127.0.0.1:8080/healthz   # {"status":"ok","olcrtc_version":"<sha>"}
```

> ℹ️ Стандартный `sh update.sh` **тоже** автоматически подтягивает свежее ядро: он сам запрашивает SHA
> последнего коммита апстрима и прокидывает его в сборку. Если GitHub API недоступен (нет сети/лимит) —
> апдейт продолжается со старым ядром и **не** ломается.

После завершения **подожди 60–90 секунд** (контейнеры разгоняются, Octane стартует) → проверка:

```bash
set +H; cd /opt/xboard && curl -sS -o /dev/null -w 'HTTP %{http_code}\n' --max-time 10 http://127.0.0.1/ && echo '---' && docker compose ps --format 'table {{.Service}}\t{{.Status}}\t{{.Ports}}' | head -6
```

Ожидаемый ЗЕЛЁНЫЙ вывод:
```
HTTP 200
---
SERVICE    STATUS                    PORTS
redis      Up (healthy)              6379/tcp
olcrtc-manager Up (healthy)          8080/tcp
xboard     Up (healthy)              0.0.0.0:80->7001/tcp, 0.0.0.0:7001->7001/tcp
```

---

## 3. 💳 Убрать ДЕМО-платежи · Настроить настоящие ЮKassa + ЮMoney (1 команда)
> По умолчанию AUTO-SEED ставит **ДЕМО ЮKassa shop_id=548791** — платежи не проходят реально, пока ты не вставишь свои ключи.

### 3.1 🔑 ГДЕ ВЗЯТЬ КЛЮЧИ (1 минуту)
1. **ЮKassa**: вход в ЛК → https://yookassa.ru/my
   - **shopId** → **Настройки → Магазин** (цифры, напр. `123456`)
   - **Секретный ключ** → **Интеграция → Ключи API** (https://yookassa.ru/my/merchant/integration/api-keys), начинается с `live_` или `test_`
   - **HTTP-уведомления** → https://yookassa.ru/my/merchant/integration/http-notifications (нужно на шаге 3.3)
2. **ЮMoney** — два варианта:
   - **как способ оплаты внутри ЮKassa** (отдельная интеграция НЕ нужна): включи **Настройки → Способы оплаты → ЮMoney** в ЛК ЮKassa;
   - **напрямую на кошелёк ЮMoney** (проще всего, **БЕЗ ИП и организации** — см. шаг 3.4): нужен только номер кошелька и «секрет для уведомлений» из https://yoomoney.ru/transfer/myservices/http-notification

### 3.2 ⚡ КОМАНДА «ВСТАВЬ КЛЮЧИ И ГОТОВО» (копируй целиком на VPS)
Команда спросит **shop_id** (вводишь цифрами, Enter) и **секретный ключ** (ввод скрытый, копируй-повляй). Если ничего не вводить → останется demo.

```bash
cd /opt/xboard && echo '=== Настройка реальных платежей: ЮKassa + ЮMoney ===' && read -p 'YOOKASSA_SHOP_ID (например 548791 или Enter=demo): ' YID && read -sp 'YOOKASSA_SECRET_KEY (скрытый ввод, копируй и вставляй): ' YSEC && echo '' && sudo cp -a .env .env.PAYMENTS-BACKUP-$(date +%H%M) && sed -i "/^YOOKASSA_SHOP_ID=/c\YOOKASSA_SHOP_ID=${YID:-548791}" .env && sed -i "/^YOOKASSA_SECRET_KEY=/c\YOOKASSA_SECRET_KEY=${YSEC:-test_SecretKey}" .env && sed -i '/^YOOMONEY_WALLET=/c\YOOMONEY_WALLET=4100119445638573' .env && sed -i '/^YOOMONEY_CLIENT_ID=/c\YOOMONEY_CLIENT_ID=1626E206' .env && sed -i '/^YOOMONEY_CLIENT_SECRET=/c\YOOMONEY_CLIENT_SECRET=5C1AF' .env && echo '✅ Ключи сохранены в .env. Пересобираем xboard-web контейнер...' && docker compose up -d --force-recreate xboard-web && sleep 20 && echo '' && echo '🎉 ГОТОВО! Реальные платежи ЮKassa / ЮMoney активны.' && echo '👉 Проверь в админке → «Платежи» → статус ЮKassa = ВКЛЮЧЕНА.'
```

### 3.3 🌐 Webhook ЮKassa (ЧТОБЫ ПЛАТЕЖИ ПРИХОДИЛИ АВТОМАТИЧЕСКИ)
1. Открой админку → **Платежи** → справа от ЮKassa нажми **Редактировать**
2. Скопируй поле **«Webhook URL»** целиком — оно выглядит как `https://домен/api/v1/guest/payment/notify/yookassa/<UUID>` (UUID в конце обязателен)
3. Открой ЛК ЮKassa → **Интеграция → HTTP-уведомления** (https://yookassa.ru/my/merchant/integration/http-notifications) → вставь скопированный URL → включи события **payment.succeeded**, **payment.canceled**, **payment.waiting_for_capture**, **refund.succeeded** → сохрани

> ⚠️ ЮKassa принимает уведомления **только по HTTPS на порту 443** (или 8443). Адрес `http://IP:7001/...` она отклонит — для реальных платежей нужен домен с TLS-сертификатом.

### 3.4 🟣 ЮMoney напрямую на кошелёк (БЕЗ ИП и организации)
Самый простой способ: деньги приходят на **личный кошелёк ЮMoney**. Ни договоров, ни кассы, ни API-ключей.
1. В админке → **Платежи → Добавить платёжный метод → «ЮMoney (оплата на кошелёк, без ИП)»**
2. Заполни поля:
   - **Номер кошелька ЮMoney** — `4100…` (только цифры; с главной страницы ЛК https://yoomoney.ru)
   - **Секрет для уведомлений** — из ЛК ЮMoney → **Настройки → HTTP-уведомления** → https://yoomoney.ru/transfer/myservices/http-notification (кнопка «Показать секрет»)
   - **Способ оплаты** — выбери из списка **«Авто — пусть плательщик выберет сам»** (можно зафиксировать «Банковская карта (AC)» или «Кошелёк ЮMoney (PC)»)
   - **URL возврата** — оставь **пустым** (вернём на страницу заказа автоматически)
3. **Сохрани** → включи переключатель метода.
4. Скопируй поле **«Webhook URL»** — вид `https://домен/api/v1/guest/payment/notify/yoomoney/<UUID>` (UUID обязателен).
5. В ЛК ЮMoney → **Настройки → HTTP-уведомления** → вставь этот URL в поле «URL для уведомлений», включи «Отправлять HTTP-уведомления» → сохрани.

> ✅ ЮMoney (в отличие от ЮKassa) принимает и **HTTP**, и **HTTPS** — для тестов подойдёт `http://IP:7001/...`, для реальной работы нужен домен (чтобы пользователь вернулся на сайт).
> Можно включить **оба** способа сразу (ЮKassa + ЮMoney) — пользователь выберет удобный.

**Необязательно: доступ к кошельку через приложение ЮMoney (OAuth).**
Создай приложение на https://yoomoney.ru/settings/oauth-services («Доступ к кошельку»), затем в админке заполни поля **5) client_id**, **6) client_secret**, **7) Redirect URI** (`https://домен/yoomoney/callback`), **8) Notification URI** (`https://домен/yoomoney-webhook`) — и один раз открой в браузере `https://домен/yoomoney/connect`, чтобы подключить кошелёк. На приём оплаты через Quickpay это не влияет — только дополняет его.

---

## 4. 📥 Клиенты VPN (OlcRTC совместимые)
| Платформа | Ссылка на скачивание | Что делать после установки |
|---|---|---|
| Windows / macOS / Linux | [OlcBox Releases ↗️](https://github.com/alananisimov/olcbox/releases) | Установи → ➕ → **Paste from Clipboard** → Connect ▶️ |
| Android | [owenclave Releases ↗️](https://github.com/owenewans/owenclave/releases) | Включи «Неизвестные источники» → установи APK → ➕ → **Import from clipboard** → Play ▶️ |
| iOS / iPhone | TestFlight owenclave (в разработке) | Скоро добавим прямую ссылку |

---

## 5. 📚 Как пользоваться (кабинет пользователя, 3 шага)
1. **Регистрация** (2 мин, бесплатно) → **Логин** → меню слева → **Купить подписку**
2. На **Панели управления** сверху есть блок **«💳 Как оплатить и получить ключ»** — там сразу видны все способы оплаты и тарифы с ценами; выбери тариф (30 / 90 / 365 дней) → **Оплатить** (ЮKassa / ЮMoney) → дождись статуса «Оплачен»
3. Сразу же (или если админ подтвердил вручную) → на **Панели управления** В САМОМ ВЕРХУ появится **зелёная карточка «🔑 Ваш ключ подключения OlcRTC»**:
   - Нажми **«📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)»**
   - Открой клиент → ➕ → Paste from Clipboard → **CONNECT**
   - Готово. ✅ DNS Яндекс 77.88.8.8 уже вшит в ключ.

---

## 6. ❌ Частые ошибки → РЕШЕНИЯ (quick-fix)
| Проблема | 10-секундное решение |
|---|---|
| `curl localhost:80 → Connection refused` / ERR_EMPTY_RESPONSE | Подожди 90 секунд после install.sh (Octane стартует ~1 мин). Потом `docker compose ps` — все 3 Up (healthy). |
| **Белые карточки** в ЛК / синяя кнопка «Войти» (не матрица) | Обнови панель командой из **Шага 2** (RC7.5 исправляет тему 100% страниц ЛК/Админки). |
| **Нет ключа** после того, как админ подтвердил заказ вручную | Обнови панель ≥ RC7.5 (добавлен endpoint `/api/olcrtc/recreate`, ключ создаётся авто). |
| QR не читается телефоном с 1-го раза | Обнови панель ≥ RC7.3 → у QR появилась белая рамка 12px + contrast 1.42 → 100% decode с первого раза. |
| Контейнер `olcrtc-manager` собирается локально 90 секунд | GitHub → Packages → `xboard-olcrtc-manager` → ⚙️ Settings → Danger Zone → **Change visibility → Public**. Следующие сборки скипятся. |
| Страница не прокручивается / скролл сломался | Обнови панель ≥ RC7.2 (добавлен watchdog `unlockScroll()` каждые 800ms, перезабивает AntD inline style). |

---

## 7. 🔐 Что находится в режиме «OlcRTC-only»
При установке AUTO-SEED **вырезает всё лишнее**:
- ❌ V2Ray / Trojan / Shadowsocks узлы (таблица `v2_server` пустая)
- ❌ Alipay / Coinbase / BTCPay / Telegram / 7 лишних платёжных шлюзов
- ❌ Legacy маршруты getSubscribe / server/fetch (возвращают stub HTTP 200, чтобы не было «Unknown Error»)

**Остаётся только нужное для продаж OlcRTC:**
- ✅ Выдача ключей `olcrtc://jitsi?datachannel@https://<JITSI-ХОСТ>/olcrtc-xxxxxxxx#<64 hex>$olc`
- ✅ Виджет с ключом **на главной Панели управления** (сразу после покупки)
- ✅ ЮKassa / ЮMoney (оплата картами, СБП, Сбер)
- ✅ 3 тарифа (Базовый 30д · Профи 90д · Максимум 365д)
- ✅ 6 статей Базы знаний (пошаговые подключения для OlcBox/owenclave)
- ✅ Бесплатный **тест 3 дня (72 часа)** (1 раз на аккаунт)
- ✅ Матрично-зелёный хакерский дизайн + анимированные фейковые счётчики

---

## 8. 🔑 Ключ OlcRTC: рабочий формат и что делать, если «не подключается»

### 8.1 Рабочий формат ключа

```
olcrtc://jitsi?datachannel@https://<JITSI-ХОСТ>/olcrtc-xxxxxxxx#<64 hex crypto_key>$olc
```

- `jitsi` — провайдер, `datachannel` — транспорт (меняются в настройках плагина);
- **`room.id` ОБЯЗАН быть ПОЛНЫМ URL комнаты** (`https://meet.egovm.ru/olcrtc-xxxxxxxx`),
  а не голым UUID — с UUID клиент не поднимает туннель;
- после `#` — 64-символьный `crypto_key`; хвост `$olc` — свободная метка из спецификации URI v1 и **сохраняется** в ключе (так ключ совпадает с рабочим эталоном клиента).

Домен Jitsi задаётся ОДНОЙ переменной окружения менеджера (по умолчанию `https://meet.egovm.ru`):

```bash
# /opt/xboard/.env  (или compose-файл)
OLCRMGR_JITSI_SERVER=https://meet.egovm.ru
```

Применить без пересборки веб-образа:

```bash
cd /opt/xboard && docker compose up -d --force-recreate olcrtc-manager
```

> 🔁 Смена Jitsi-домена и фикс room-URL действуют на **новые/пересозданные** инстансы.
> Чтобы перевыпустить ключ — нажми «🔄 Пересоздать ключ (обновить)» в ЛК
> (или выполни серверную команду из 8.3).

### 8.2 Диагностика «ключ есть, но не подключается» (5 команд)

```bash
cd /opt/xboard

# 1. В конфиге инстанса room.id должен быть URL, а НЕ uuid
docker exec xboard-olcrtc-manager sh -lc 'cat /var/lib/olcrtc-manager/instances/*.yaml | head -40'
#  Ожидаем: room: { id: "https://meet.egovm.ru/olcrtc-xxxxxxxx" }

# 2. Процесс olcrtc запущен и не сыпется
docker exec xboard-olcrtc-manager sh -lc 'ls -la /var/lib/olcrtc-manager/instances/'
docker exec xboard-olcrtc-manager sh -lc 'tail -50 /var/lib/olcrtc-manager/instances/*.log'

# 3. Jitsi-хост доступен с VPS
docker compose exec olcrtc-manager sh -lc 'wget -q -O /dev/null -S https://meet.egovm.ru 2>&1 | head -3'

# 4. Диагностика стека Xboard → olcrtc-manager
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec xboard curl -sS -H "Authorization: Bearer ${KEY}" \
  http://olcrtc-manager:8080/api/v1/instance/list | head -c 400
echo ""

# 5. Персональная диагностика ключа пользователя (открой в браузере, будучи залогиненным):
#    http://<IP>:7001/api/olcrtc/diag?token=<токен пользователя>
```

### 8.3 Перевыпуск ключа (серверная сторона, если кнопка в ЛК недоступна)

```bash
cd /opt/xboard
docker compose exec xboard php artisan tinker --execute='
$u   = \App\Models\User::where("email","user@mail.ru")->firstOrFail();
$url = rtrim((string) admin_setting("olcrtc_manager_url","http://olcrtc-manager:8080"), "/");
$key = \Plugin\OlcRTC\Services\OlcRTCManagerClient::resolveApiKey($url, (string) admin_setting("olcrtc_manager_api_key",""));
$mgr = new \Plugin\OlcRTC\Services\OlcRTCManagerClient(
    $url, $key,
    (string) admin_setting("olcrtc_default_provider","jitsi"),
    (string) admin_setting("olcrtc_default_transport","datachannel"),
    (string) admin_setting("olcrtc_default_dns","77.88.8.8:53"),
    (string) env("OLCRMGR_DEFAULT_TOKEN","")
);
echo json_encode($mgr->createOrUpdateInstance($u->id, (int)$u->expired_at, "olc", true), JSON_UNESCAPED_SLASHES), PHP_EOL;'
```

> ℹ️ Клиент **нельзя** получить через `app(...)` — у него 6 строковых аргументов конструктора, поэтому
> создаём вручную (как в `plugins/OlcRTC/Plugin.php::client()`). `resolveApiKey()` сам подберёт рабочий ключ.

### 8.4 Эндпоинты ЛК-виджета (для отладки из браузера)

| Метод | Путь | Назначение |
|---|---|---|
| GET  | `/api/olcrtc/widget?token=…`      | данные виджета (ключ, тариф, срок, ссылки) |
| GET  | `/api/olcrtc/diag?token=…`        | диагностика auth/manager/instance |
| GET  | `/api/olcrtc/trial-status?token=…`| статус тестового периода |
| GET  | `/api/olcrtc/sub?token=…`         | plain-text ссылка-подписка (`olcrtc://`) |
| GET  | `/api/olcrtc/yaml?token=…`        | скачать `client.yaml` |
| POST | `/api/olcrtc/claim-trial`         | активировать тест (3 дня / 72 ч) |
| POST | `/api/olcrtc/recreate`            | пересоздать ключ (кнопка «🔄» в ЛК) |

### 8.5 Клиенты

Только **OlcBox** (Windows / macOS / Linux) и **owenclave** (Android) — они поддерживают
транспорт `datachannel`. Старые Veil и прочие дают `unknown transport datachannel`.

---

> ⚠️ **ВАЖНО**: СМЕНИТЕ АДМИН-ПАРОЛЬ ИЗ `Admin123456` НА СВОЙ СРАЗУ ПОСЛЕ ПЕРВОГО ВХОДА (меню профиля справа → «Сменить пароль»).
> ⚠️ **НИКОГДА НЕ КОММИТЬ ФАЙЛ `.env` В GITHUB**. Он содержит APP_KEY / OLCRMGR_API_KEY / платёжные секреты.
