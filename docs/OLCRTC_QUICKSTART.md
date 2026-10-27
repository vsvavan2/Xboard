# OLCRTC_QUICKSTART.md — Полная настройка плагина OlcRTC + ЮKassa

> Цель этого документа: после завершения установки (INSTALL.md или one-click install.sh)
> довести VPN-панель до состояния **«пользователь регистрируется → получает тест 3 дня (72 ч) →
> оплачивает подписку картой/СБП → получает рабочие olcrtc:// ссылки и YAML»**
> **полностью автоматически**, без ручной выдачи.

---

## ⭐ Глава 0. AUTO_SEED=1 — ЧТО УЖЕ СДЕЛАНО АВТОМАТИЧЕСКИ (ничего нажимать не нужно!)

Если вы запустили `install.sh` (одной командой как написано в README), то **в админке уже есть 100% настроек из таблиц ниже**.
Откройте админку один раз и просто убедитесь, что пункты 2–5 уже заполнены:

| Что было сделано AUTO-SEEDом               | Статус по умолчанию                                                                                                                                |
|--------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| 🧩 Плагин OlcRTC Integration               | ✅ Enabled=1. Manager URL=`http://olcrtc-manager:8080`. DNS=Яндекс 77.88.8.8:53. Provider=Jitsi, Transport=datachannel. Trial=72 часа (3 дня) включён.  |
| 💳 Платёжка ЮKassa                         | ✅ Enabled=1. Shop ID=548791 (демонстрационный). Методы=bank_card/sberbank/yoomoney/sbp/tinkoff. 💡 см. §4.1 как вставить РЕАЛЬНЫЕ live_xxx.   |
| 🟣 Платёжка ЮMoney (напрямую на кошелёк)   | ➖ Создаётся автоматически. Включается сразу, если в `.env` заданы `YOOMONEY_WALLET` + `YOOMONEY_NOTIFICATION_SECRET`, иначе — выключена до настройки в админке. 💡 см. §4.4 (без ИП). |
| 🖥️ Группа серверов «Все пользователи VPN» | ✅ 1 строка id=1.                                                                                                                                  |
| 💰 3 тарифа VPN (Базовый/Профи/Максимум)  | ✅ Цены в рублях: 199 ₽/мес / 499 ₽/квартал / 1499 ₽/год. Sell=1, Show=1.                                                                        |
| 📚 База знаний v2_knowledge (пользователям)| ✅ 4 статьи RU с скрин-ориентированными инструкциями для OlcBox (Win/Mac/Linux) + owenclave Android + FAQ + общий «как подключиться за 4 шага». |

Если вы НЕ видите этих пунктов — просто перезапустите:
```bash
cd /opt/xboard
export AUTO_SEED=1 AUTO_INSTALL=1 && docker compose restart xboard && sleep 30 && docker compose logs --tail 60 xboard | grep "AUTO-SEED"
```
Ожидаемый вывод: `Шаг 3.5/4 AUTO-SEED` и 4–5 зелёных галочек ·.

---

## Оглавление

1. [Перед началом: контекст плагина OlcRTC Integration](#1-перед-началом-контекст-плагина-olcrtc-integration)
2. [Шаг А — Настроить плагин OlcRTC (админка)](#2-шаг-а--настроить-плагин-olcrtc-админка)
3. [Шаг Б — Проверить соединение Xboard ↔ olcrtc-manager](#3-шаг-б--проверить-соединение-xboard--olcrtc-manager)
4. [Шаг В — Подключить поставщика платежей (ЮKassa и/или ЮMoney)](#4-шаг-в--подключить-юkassa-поставщика-платежей)
5. [Шаг Г — Создать тарифы VPN](#5-шаг-г--создать-тарифы-vpn)
6. [Шаг Д — End-to-end проверка (от регистрации до ссылки)](#6-шаг-д--end-to-end-проверка-от-регистрации-до-ссылки)
7. [Типовые проблемы после настройки (и их quick-fix)](#7-типовые-проблемы-после-настройки-и-их-quick-fix)

---

## 1. Перед началом: контекст плагина OlcRTC Integration

**Архитектура (повторение из README):**

```
  Браузер пользователя                       Docker Compose на VPS
  ===================                        =====================================
  Регистрация /login ─────► :7001 xboard ──► Laravel → Плагин OlcRTC Integration
  Оплата ЮKassa         (Caddy+Octane)        │ HTTP-JSON API + HS256
  Кабинет olcrtc:// URL                      ▼
                                        olcrtc-manager (Go, :8080)
                                            │ fork_exec olcrtc mode=srv
                                            ├─► user #1 → udp/tcp:443xx (unique room+key)
                                            └─► user #2 → udp/tcp:443xx (unique room+key)
```

**Гарантии, которые предоставляет плагин (уже в коде):**
- 🆕 **Hook `user.register.after`** → сразу после регистрации пользователя (email+пароль) плагин вызывает `/instance/create` Go-менеджера → **тестовый период 3 дня (72 часа)** (настраивается в конфиге плагина).
- 💰 **Hook `order.open.after`** → сразу после того как вебхук ЮKassa `payment.succeeded` пришёл, OrderService пометил заказ как STATUS_COMPLETED (вызвал open()) и продлил `expired_at` у пользователя — плагин сразу же вызывает OlcRTC manager → createOrUpdateInstance для user_id → VPN instance создаётся / продлевается на новый срок.
- ⏹️ **Крон Laravel (каждые 15 мин)** + **менеджер (каждые 30 сек)** → сверяют `expires_at`. Если просрочено — инстанс останавливается (`SIGTERM` бинарника olcrtc).

---

## 2. Шаг А — Настроить плагин OlcRTC (админка)

Откройте админку. Если не помните путь — **копи-паста команда**:
```bash
cd /opt/xboard
apt-get install -y sqlite3 >/dev/null 2>&1
SEC=$(sqlite3 ./.docker/.data/xboard.sqlite "SELECT value FROM v2_system_config WHERE name='secure_path' LIMIT 1;")
IP=$(curl -s --max-time 5 https://ifconfig.me || echo "127.0.0.1")
echo "Админка: http://${IP}:7001/${SEC}"
echo "Логин  : admin@example.com / Admin123456"
```

Перейдите в админку → слева в меню **«Плагины» (или 插件)**.
Рядом с **OlcRTC Integration v1.0.0** кликните **«Настроить» (или 配置)**.

Заполните **КАЖДОЕ** поле (пустые = undefined поведение):

| Поле UI (англ/кит)                 | Значение для 99% случаев                                                                       |
|------------------------------------|------------------------------------------------------------------------------------------------|
| **URL / API地址 of olcrtc-manager**| `http://olcrtc-manager:8080` **(именно так — не меняйте!)**                                  |
| **API Key / API密钥**              | скопируйте из VPS: `grep '^OLCRMGR_API_KEY=' /opt/xboard/.env \| cut -d= -f2`                 |
| **OlcRTC Provider /提供商**        | `jitsi`                                                                                        |
| **Transport / 传输协议**           | `datachannel`                                                                                  |
| **Trial (hours) /试用期**          | `72`                                                                                           |
| **Enable trial /启用试用期**       | ☑️ Да (включить галочку)                                                                       |
| **DNS Resolver / DNS服务器**       | `8.8.8.8:53` (или российский `77.88.8.8:53` для быстрого резолва)                              |
| **Auto-create instance**           | ☑️ Да (создавать инстанс при регистрации пользователя)                                        |
| **Enable auto payment extend**     | ☑️ Да (продлевать срок при успешной оплате)                                                    |

> 💡 **Почему именно `http://olcrtc-manager:8080`?**
> Docker Compose создаёт внутреннюю DNS-зону: каждому сервису соответствует hostname его
> `service:` ключа из `docker-compose.yml` (у нас `olcrtc-manager`). Внутренняя сеть `xboard-net`,
> порт менеджера `8080` **НЕ торчит наружу** — только Xboard-web контейнер может туда ходить.
> Никогда не пишите `http://127.0.0.1:8080` (localhost контейнера Xboard — там не слушает!).

> 🛰 **Jitsi-сервер (room URL).** По конвенции olcrtc `room.id` для провайдера `jitsi` должен быть
> **полным URL комнаты** вида `https://host/room`. Менеджер формирует его как
> `<OLCRMGR_JITSI_SERVER>/olcrtc-<random>`, поэтому итоговый ключ выглядит так:
> `olcrtc://jitsi?datachannel@https://meet.ffmuc.net/olcrtc-uarqkcdq#<64 hex>$olc`.
> Домен задаётся переменной окружения контейнера `olcrtc-manager` (по умолчанию `https://meet.ffmuc.net`;
> подойдёт любой Jitsi без авторизации и без токена, напр. `https://meet.opensuse.org`). Чтобы сменить — отредактируйте
> `OLCRMGR_JITSI_SERVER` в `compose.yaml` (или `.env`) и выполните
> `docker compose up -d olcrtc-manager`. Уже выданные ключи со старым (не-URL) room автоматически
> мигрируют при следующем старте менеджера.

Жмите **«Сохранить / 保存»**.

---

## 3. Шаг Б — Проверить соединение Xboard ↔ olcrtc-manager

**Самый важный smoke test** — если он не проходит, то плагин показывает «включено» но ничего не создаёт.

### 3.1 Ручной ping менеджера изнутри контейнера Xboard

```bash
cd /opt/xboard
# 1. Дёргаем /health Go-менеджера (JSON 200)
docker compose exec xboard curl -sS --max-time 5 http://olcrtc-manager:8080/health
# Ожидаем: {"status":"ok","uptime_seconds":...}

# 2. Дёргаем /instance/list с нашим API-key (ХЕДЕР Authorization: Bearer <OLCRMGR_API_KEY>)
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec xboard curl -sS --max-time 5 \
  -H "Authorization: Bearer ${KEY}" \
  http://olcrtc-manager:8080/api/v1/instance/list | head -c 600
echo ""
# Ожидаем: {"code":0,"data":[]} — пустой массив (ещё нет пользователей) = OK.
```

Если 401 Unauthorized → ключ скопировали неправильно (лишние пробелы, \n и т.д.).
Если `Could not resolve host` → контейнер `olcrtc-manager` не `Up`. Запустите `docker compose up -d`.

### 3.2 (опционально) Проверка через artisan tinker — create/extend/delete

```bash
cd /opt/xboard
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose run --rm --entrypoint "sh -lc" xboard "php artisan tinker <<'EOF'
\\\Illuminate\\Support\\Facades\\Http::withHeaders(['Authorization'=>'Bearer ${KEY}'])
  ->timeout(5)->post('http://olcrtc-manager:8080/api/v1/instance/create', [
    'user_uuid' => 'TEST-USER',
    'plan_code' => 'OLCRTC_TRIAL',
    'duration_seconds' => 3600,
  ])->json()
EOF"
```

В ответе должно быть `"code":0` и `data.instance_id` (UUID). Если да — Xboard ↔ Go-manager раскачивается.
Удалите тестовый инстанс сразу:
```bash
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec olcrtc-manager \
  curl -sS -X DELETE -H "Authorization: Bearer ${KEY}" \
  http://127.0.0.1:8080/api/v1/instance/by-user-uuid/TEST-USER
```

---

## 4. Шаг В — Подключить поставщика платежей (ЮKassa и/или ЮMoney)

Можно включить **любой из двух способов** (или оба сразу — пользователь выберет удобный):

- **ЮKassa** — эквайринг (карты/СБП/Сбер/ЮMoney), нужен магазин в ЮKassa (§4.1–4.3).
- **ЮMoney напрямую на кошелёк** — **без ИП и организации**, только личный кошелёк ЮMoney (§4.4). Самый простой вариант.

### 4.0 Где что настраивается (ссылки ЛК ЮKassa / ЮMoney)

Всё настраивается **в личном кабинете ЮKassa** (ЮMoney тоже можно подключить там, как способ оплаты — тогда отдельная интеграция не нужна):

| Что нужно | Куда идти |
|-----------|-----------|
| Вход в ЛК ЮKassa | https://yookassa.ru/my |
| **shopId** (ID магазина, цифры) | ЛК → **Настройки → Магазин** (поле shopId) |
| **secret_key** (`live_...` / `test_...`) | ЛК → **Интеграция → Ключи API** — https://yookassa.ru/my/merchant/integration/api-keys |
| **HTTP-уведомления** (вебхук) | ЛК → **Интеграция → HTTP-уведомления** — https://yookassa.ru/my/merchant/integration/http-notifications |
| Подключить **кошелёк ЮMoney / СБП / СберPay** как способ оплаты внутри ЮKassa | ЛК → **Настройки → Способы оплаты** (галочки у методов) |
| **ЮMoney напрямую на кошелёк** (без ИП): номер кошелька | главная ЛК https://yoomoney.ru — номер вида `4100112345678901` |
| **ЮMoney напрямую**: секрет уведомлений | ЛК ЮMoney → **Настройки → HTTP-уведомления** — https://yoomoney.ru/transfer/myservices/http-notification (кнопка «Показать секрет») |
| Документация API | https://yookassa.ru/developers/using-api/interaction-format · https://yookassa.ru/developers/using-api/webhooks |

Важные правила ЮKassa (иначе платежи не пройдут):

1. **Одна пара ключей** — shopId + secret_key из одного раздела. Ключ `test_*` работает только с тестовым магазином, `live_*` — только с боевым.
2. **URL уведомлений обязан быть HTTPS на порту 443 или 8443.** Сертификат подойдёт любой, включая самоподписанный; HTTP и другие порты (например наш `:7001`) ЮKassa отклоняет. Т.е. для авто-подтверждения оплат нужен домен + TLS (или проброс через nginx/Cloudflare Tunnel).
3. Ключи, записанные в `.env` (`YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET_KEY`), подставляются только при **первой** установке (AUTO_SEED). Если платёжка `yookassa` уже создана — менять ключи надо в админке.
4. Метод **ЮMoney напрямую** (§4.4) — это **отдельная платёжка** в Xboard (sign=`yoomoney`), она **не требует** ИП/организации/кассы. Переменные `.env` `YOOMONEY_WALLET` и `YOOMONEY_NOTIFICATION_SECRET` заполняются при первой установке; иначе — вручную в админке. Уведомления ЮMoney принимает и по HTTP, и по HTTPS.

### 4.1 Создать платёжный метод в Xboard

Админка → **«Платёжки / 支付方式» → Добавить / 添加** → **ЮKassa (YooKassa)**.

| Поле UI                          | Значение                                                                                   |
|----------------------------------|--------------------------------------------------------------------------------------------|
| **Display Name / 显示名称**       | `Оплата картой / СБП / ЮMoney`                                                            |
| **Sign / 标识**                  | `yookassa` (уникально, не меняйте потом — он идёт в URLs)                                  |
| **Merchant ID / 商户号 (Shop ID)**| из ЛК ЮKassa: **Настройки → Магазин** (поле shopId) → ID магазина (например `548791`)  |
| **App Key / 密钥 (Secret)**      | из ЛК ЮKassa: **Интеграция → Ключи API → Секретный ключ** (формат `live_...`)                  |
| **Payment methods / 支付方式**    | `bank_card,sberbank,yoomoney,sbp,tinkoff_bank,bank131`                                     |
| **Auto capture / 自动扣款**       | ☑️ Да (automatic capture после успешной авторизации — иначе деньги висят 30 минут и отскочат) |
| **Enable / 启用**                 | ☑️ Да                                                                                       |

### 4.2 (тест, необязательно) Тестовый режим ЮKassa

ЮKassa предоставляет бесплатный демо-магазин:
```
Shop ID:  548791
Secret:   test_...  (взять из https://yookassa.ru/my/merchant/integration/api-keys)
```

В тестовом режиме можно платить **тестовыми картами**:
```
Номер карты   : 2200 0000 0000 0004   (успех)
              : 5555 5555 5555 5599   (отклонено)
Срок          : 2030-12
CVC           : 123
3DS SMS       : любой 6-значный код
```

### 4.3 Критично! Вебхук ЮKassa на Xboard

> Без вебхука Xboard **не узнает об успешной оплате** и не продлит OlcRTC инстанс!
> Ручной проверки нет (только крон раз в 15мин, но он зависит от ЮKassa API).

Откройте ЛК ЮKassa → **Интеграция → HTTP-уведомления**
(прямая ссылка: https://yookassa.ru/my/merchant/integration/http-notifications).

Включите флажки **минимум**:
- `payment.succeeded` — успех
- `payment.canceled` — отменён
- `payment.waiting_for_capture` (если выключен `Auto capture`, см выше)
- `refund.succeeded` — возврат

В **URL уведомления** впишите адрес из админки: **Платёжки → ЮKassa → Редактировать → поле «Webhook URL» → копировать**
(домен замените на свой; `<UUID>` — 8-символьный uuid вашей платёжки, без него роут отдаст 404):
```
https://vpn.example.ru/api/v1/guest/payment/notify/yookassa/<UUID>
```

> ⚠️ Адрес вида `http://IP:7001/...` ЮKassa **не примет** — требуется HTTPS на порту **443 или 8443** (сертификат годится любой). Для локальной проверки роута см. 4.5.

> ⚠️ ЮKassa требует **ответа HTTP 2xx с пустым JSON** `{}` на вебхук за 3 сек. Если Xboard перегружен — ЮKassa повторяет 10 раз с экспоненциальной задержкой. Плагин YooKassa драйвер гарантирует idempotent обработку (signature HMAC-SHA-256 проверяется!).

### 4.4 🟣 ЮMoney напрямую на кошелёк (БЕЗ ИП и организации)

Самый простой способ приёма оплаты: деньги приходят сразу на **личный кошелёк ЮMoney**. Не нужны ИП, ООО, договоры, онлайн-касса и API-ключи — только номер кошелька и «секрет для уведомлений». Это **отдельная платёжка** (sign=`yoomoney`), её можно включить вместе с ЮKassa.

**Шаг 1. Создать метод в Xboard**

Админка → **«Платежи / 支付方式» → Добавить** → **ЮMoney (оплата на кошелёк, без ИП)**.

| Поле UI                            | Значение                                                                                       |
|------------------------------------|------------------------------------------------------------------------------------------------|
| **Номер кошелька ЮMoney**          | номер вашего кошелька `4100…` (**только цифры**); берётся на главной https://yoomoney.ru         |
| **Секрет для уведомлений**         | ЛК ЮMoney → **Настройки → HTTP-уведомления** → https://yoomoney.ru/transfer/myservices/http-notification → кнопка «Показать секрет» |
| **Способ оплаты по умолчанию**     | выбрать в списке **«Авто — пусть плательщик выберет сам»** (можно зафиксировать карту `AC` или кошелёк `PC`) |
| **URL возврата после оплаты**      | оставить **пустым** (вернём на страницу заказа автоматически)                                     |
| **Enable / 启用**                  | ☑️ Да                                                                                          |

Нажмите `Сохранить` → включите слайдер метода.

**Шаг 2. Прописать Notification URI в ЛК ЮMoney (обязательно!)**

Скопируйте из карточки метода поле **«Webhook URL»** и вставьте его в ЛК ЮMoney → **Настройки → HTTP-уведомления** (https://yoomoney.ru/transfer/myservices/http-notification) → поле «URL для уведомлений» → включите галочку «Отправлять HTTP-уведомления» → сохранить:

```
https://vpn.example.ru/api/v1/guest/payment/notify/yoomoney/<UUID>
```
`<UUID>` — 8-символьный uuid **именно этой** платёжки (у каждого метода свой; без него роут отдаст 404).

**Как работает оплата ЮMoney:** пользователь жмёт «Оплатить» → редирект на форму `yoomoney.ru/quickpay/confirm.xml` → оплата → возврат на страницу заказа → ЮMoney шлёт HTTP-уведомление на Notification URI → плагин проверяет подпись `sha1_hash` (по «секрету для уведомлений») → заказ помечается оплаченным → OlcRTC-инстанс создаётся/продлевается.

> ✅ ЮMoney (в отличие от ЮKassa) принимает уведомления и по **HTTP**, и по **HTTPS** — для тестов можно указать `http://IP:7001/...`. Для реальных платежей и возврата пользователя на сайт нужен домен.
> 🧪 Smoke-test роута: `curl -si "http://IP:7001/api/v1/guest/payment/notify/yoomoney/<UUID>"` — роут жив, если ответ не 404.

### 4.5 Проверка вебхука (smoke-test из консоли)

Сымитируем `payment.succeeded` с неправильной подписью — Xboard должен ответить HTTP 403.
```bash
IP=$(curl -s --max-time 5 https://ifconfig.me || echo "127.0.0.1")
curl -sv -X POST "http://${IP}:7001/api/v1/guest/payment/notify/yookassa" \
  -H "Content-Type: application/json" \
  -d '{"type":"notification","event":"payment.succeeded","object":{}}'
# Ожидаем: HTTP/1.1 403 Forbidden  (подпись неверная → 403, значит роут жив!)
```

Если 404 Not Found — роут не зарегистрирован (драйвер YooKassa не включён как плагин). Проверьте код `yookassa` в списке плагинов.

---

## 5. Шаг Г — Создать тарифы VPN

Админка → **«Тарифы / 套餐 / Plan» → Добавить / 添加 план**.

Создайте **3 тарифа** (минимум — для продажи и для теста):

| #  | Название UI                     | Цена (рубли) | Период  | Теги               | OlcRTC plan-code (в config) |
|----|---------------------------------|-----------------|---------|--------------------|---------------------------|
| G1 | **Пробный (1 день) OlcRTC**     | `1`   | day, 1d| promo, trial       | `OLCRTC_TRIAL_1D`         |
| G2 | **Базовый OlcRTC (месяц)**      | `299`  | month, 30d | basic        | `OLCRTC_BASIC_M`          |
| G3 | **OlcRTC Pro + СБП priority**   | `499`  | month, 30d | pro, fast    | `OLCRTC_PRO_M`            |

💡 **Важно про цены:** Xboard (в этом форке) хранит и принимает цены тарифов **в рублях**.
Вводите рубли как есть (`299` = 299 ₽); при желании можно с копейками (`299.50` = 299,50 ₽).
Лимит трафика в тарифе указывается **в ГБ** (0 = безлимит).

После создания **слайдер Enabled / 启用 переведите в ВКЛ.** для каждого тарифа.
Тариф с `enabled=false` пользователь **не увидит** в ЛК.

---

## 6. Шаг Д — End-to-end проверка (от регистрации до ссылки)

### 6.1 Регистрация пользователя (должна выдать тест на 3 дня / 72 ч)

Откройте **«Личный кабинет / ЛК пользователя»** (НЕ админка!):
```
http://<IP-VPS>:7001/
```
Нажмите **«Регистрация / 注册»**.

Заполните:
- Email: `test-user-01@mailinator.com` (чтобы потом ловить письма; любой email подойдёт)
- Пароль: `TestUser01!`
- Промокод: *(пусто)*

### 6.2 Сразу проверьте в БД — появился ли пользователь и включён ли ему инстанс

На VPS (root):
```bash
cd /opt/xboard
# ВСЕ пользователи и их OlcRTC UUID:
sqlite3 ./.docker/.data/xboard.sqlite "
SELECT id,email,uuid,is_admin,expired_at FROM v2_user ORDER BY id DESC LIMIT 3;
"
# ⬇️ И ДОБАВЬТЕ вывод id плагина olc_rtc_instance (если у плагина есть отдельная таблица)

# И в olcrtc-manager список запущенных olcrtc процессов:
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec olcrtc-manager curl -sS -H "Authorization: Bearer ${KEY}" \
  http://127.0.0.1:8080/api/v1/instance/list | python3 -m json.tool | head -50
```

✅ Успех = видите `test-user-01` UUID = instance `user_uuid` в manager-е + `expires_at` = `now() + 72 часа (3 дня)`.

### 6.3 В ЛК пользователя → раздел «Мои устройства / 我的订阅» → Ожидаем 4 ссылки

Пользователь в ЛК должен видеть:

| UI-элемент                        | Что внутри                                                                 |
|-----------------------------------|---------------------------------------------------------------------------|
| 🔗 `olcrtc://...` (кнопка Copy)  | URI-схема для клиентов OlcBox / Veil / OwenClave                           |
| 📥 `Скачать client.yaml`         | Конфиг YAML (room_id, crypto_key, stun/turn) для ручного ввода            |
| 🔔 `Sub URL (auto-update)`        | `https://.../api/v1/user/subscribe/olcrtc?token=...`                     |
| ⏰ `Осталось: 5ч 59мин`            | `expires_at` из БД                                                         |
| 💳 «Продлить»                     → переброс на оплату (шаг 6.4)                                          |

Если пусто → плагин hook `user.created` не сработал. Смотрите `docker compose logs --tail 150 xboard-web | grep Olc`.

### 6.4 Оплата тарифа тестовой картой (ЮKassa)

В ЛК на **«Панели управления»** сверху есть блок **«💳 Как оплатить и получить ключ»** — это витрина: она сама подтягивает все включённые способы оплаты и активные тарифы с ценами (из `/api/olcrtc/pay-info`). Оттуда пользователь переходит к оплате одним кликом.

Из ЛК → кнопка **«Пополнить баланс / 充值»** или **«Продлить / 续费»** напротив тарифа.
Выберите **ЮKassa → Bank Card → 1₽** (промо-тариф). Оплатите **тестовой картой**:
```
2200 0000 0000 0004, 12/30, CVC 123 → любой 6-значный SMS 3DS code
```

Через 1–3 секунды после оплаты:
1. Xboard ЛК обновится → «Оплачено, спасибо!»
2. В БД у пользователя `expired_at` продлится (плагин hook `order.open.after` вызывает OlcRTC manager → createOrUpdateInstance с новым сроком).
3. **Самая важная проверка:**
```bash
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec olcrtc-manager \
  curl -sS -H "Authorization: Bearer ${KEY}" http://127.0.0.1:8080/api/v1/instance/by-user-uuid/$(
    sqlite3 ./.docker/.data/xboard.sqlite "SELECT uuid FROM v2_user WHERE email='test-user-01@mailinator.com' LIMIT 1;"
  ) | python3 -m json.tool
```
В ответе:
```json
{
  "data": {
    "expires_at": "2026-10-11T16:30:00Z",   // ✅ продлён НА МЕСЯЦ (раньше было +72ч)
    "status": "running"
  }
}
```

🎉 **ВСЁ. Интеграция работает. Пользователи платят → получают VPN.**

---

## 7. Типовые проблемы после настройки (и их quick-fix)

### P1. Пользователь зарегистрировался, а ЛК говорит «у вас нет активной подписки»

Симптом: кнопка «Продлить» есть, но нет olcrtc:// URL.

**Диагностика:**
```bash
cd /opt/xboard
sqlite3 ./.docker/.data/xboard.sqlite "SELECT email,expired_at FROM v2_user WHERE email LIKE '%test%';"
# olcrtc manager инстансы
KEY=$(grep '^OLCRMGR_API_KEY=' .env | cut -d= -f2)
docker compose exec olcrtc-manager curl -sS -H "Authorization: Bearer ${KEY}" http://127.0.0.1:8080/api/v1/instance/list | head -c 800
```

**90% случаев root cause**: плагин не включён (OlcRTC Integration enabled=0). Быстрый фикс:
```bash
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='
    app(\App\Services\Plugin\PluginManager::class)->enable(\"olc_rtc\");
    \App\Services\Plugin\PluginManager::installDefaultPlugins();
  '"
```

### P2. После оплаты срок VPN не продлён (хук order.open.after — ошибка / не вызвался)

Смотрите laravel.log в xboard-web:
```bash
cd /opt/xboard
docker compose exec xboard grep -n "olc\|order.open.after\|order.paid\|YooKassa" storage/logs/laravel-$(date +%Y-%m-%d).log | tail -50
```

Обычный фикс (пропущенный вызов продления — вызываем руками, +30 дней от текущего `expired_at`):
```bash
cd /opt/xboard
docker compose exec xboard php artisan tinker --execute='
$u   = \App\Models\User::where("email","test-user-01@mailinator.com")->firstOrFail();
$new = max((int)$u->expired_at, time()) + 30*86400;
$url = rtrim((string) admin_setting("olcrtc_manager_url","http://olcrtc-manager:8080"), "/");
$key = \Plugin\OlcRTC\Services\OlcRTCManagerClient::resolveApiKey($url, (string) admin_setting("olcrtc_manager_api_key",""));
$mgr = new \Plugin\OlcRTC\Services\OlcRTCManagerClient(
    $url, $key,
    (string) admin_setting("olcrtc_default_provider","jitsi"),
    (string) admin_setting("olcrtc_default_transport","datachannel"),
    (string) admin_setting("olcrtc_default_dns","77.88.8.8:53"),
    (string) env("OLCRMGR_DEFAULT_TOKEN","")
);
$mgr->createOrUpdateInstance($u->id, $new, "olc");
echo "OK: new expired_at = $new\n";'
```
> ℹ️ Метода `extend()` у клиента НЕТ — продление делается повторным `createOrUpdateInstance()`
> с новым `expires_at` (room и ключ при этом сохраняются/мигрируются автоматически).
> Клиент **нельзя** получить через `app(...)` — у него 6 строковых аргументов конструктора, создаём вручную.

### P3. Пользователь получает olcrtc:// ссылку — но не подключается (TURN/STUN)

Это почти всегда **сетевой/брандмауэр VPS**: olcrtc использует случайные UDP-порты на `olcrtc-manager` контейнере.
Разрешите INPUT/OUTPUT весь трафик между своими контейнерами и external UDP:
```bash
ufw allow in on docker0 || true
ufw allow 3478/udp || true   # STUN/TURN стандартный порт
# ИЛИ (если iptables без ufw):
iptables -I INPUT -p udp -m multiport --dports 1024:65535 -j ACCEPT
```
В плагине OlcRTC config замените **Provider** с `jitsi` на `jitsi+turn` и укажите external TURN сервер (Twilio/CoTurn).

### P4. ЮKassa «Платёж прошёл, но на балансе нет денег»

**99% причин — вебхук ЮKassa не дошёл до Xboard**:
- Нет домена/HTTPS (ЮKassa блокирует HTTP без TLS для реальных магазинов)
- Неверный URL вебхука в ЛК
- Cloudflare WAF заблокировал POST
- Порт 7001 закрыт входящим iptables у хостера

**Быстрый фикс** без смены домена: поставьте [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/) → получаете `https://vpn.example.ru` бесплатно и сразу ЮKassa вебхуки 200.

---

## 📚 Далее рекомендуем

- **[docs/TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — 15 известных багов с копи-паст решениями (от UNIQUE email до ERR_EMPTY_RESPONSE на :7001).
- **[README.md](../README.md)** — самый быстрый старт одной командой и ссылки на все документации.
- **[docs/en/development/plugin-development-guide.md](../docs/en/development/plugin-development-guide.md)** — как дописать ещё один плагин оплаты (например Тинькофф-банк), или свой драйвер в OlcRTC.

---

## 8. 🛒 Клиенты для пользователей: ГДЕ СКАЧАТЬ И КАК ПОДКЛЮЧИТЬСЯ

Эти инструкции уже автоматически прописаны в **Базе знаний (v2_knowledge)** сайта для пользователей.
Здесь — краткая версия для администратора (чтобы знать, что увидит пользователь):

### 8.1 Подборка клиентов (актуально на сентябрь 2026)

| Платформа              | Рекомендуемый клиент                             | Ссылка на Releases GitHub                          | Почему он                                                                                                                                     |
|------------------------|--------------------------------------------------|----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| 💻 Windows 10/11       | **OlcBox** (alananimov)                          | https://github.com/alananisimov/olcbox/releases    | Мультиплатформенный GUI, самая быстрая поддержка новых OlcRTC, копипаст URI работает из коробки, импорты YAML.                               |
| 🍎 macOS M1/M2/M3/Intel| **OlcBox** (alananimov)                          | https://github.com/alananisimov/olcbox/releases    | То же самое: dmg 2 арха (aarch64 + x64), подписанный код.                                                                                    |
| 🐧 Linux Ubuntu/Debian | **OlcBox** (alananimov) .deb / AppImage          | https://github.com/alananisimov/olcbox/releases    | .deb пакет устанавливается через dpkg -i.                                                                                                     |
| 🤖 Android 8–15        | **owenclave** (owenewans)                        | https://github.com/owenewans/owenclave/releases    | Обновляется БЫСТРЕЕ всех под новые версии OlcRTC, обход DPI/Deep Packet Inspection, режим «Always-on VPN + Block without VPN» из коробки.   |

> ⚠️ **Не рекомендуем Veil / другие старые клиенты.** Выпуск обновлений у них остановлен,
> с OlcRTC 2.3+ они падают с «unknown transport datachannel». OlcBox и owenclave — единственные,
> которые обновляются регулярно (раз в 3–5 дней после выхода протокола).

### 8.2 Иллюстрированная инструкция (одна и та же для Win/Mac/Linux/Android)

Пользователю, после того как он оплатил тариф в ЛК, нужно сделать **РОВНО 4 ШАГА**:

```
ШАГ 1 📥  Скачать клиент по ссылке из ЛК (раздел «Скачать клиент»)
ШАГ 2 📋  В ЛК нажать «📋 Копировать ключ» (скопировать olcrtc:// URI)
ШАГ 3 ➕   Открыть клиент → кнопка «Добавить / ➕ / Import» → Paste from Clipboard
ШАГ 4 ▶️   Нажать зелёную кнопку Connect / Play / Подключить
        → когда появится значок 🔑 в статус-баре или зелёная надпись Connected = ВСЁ РАБОТАЕТ.
```

Проверка (можно давать пользователям): https://2ip.ru — должен показать IP, отличный от домашнего Wi-Fi/сотового.

### 8.3 Что видит пользователь в ЛК после покупки

`GET /api/v1/user/olcrtc` возвращает JSON (плагин OlcRTC):
```json
{
  "uri": "olcrtc://jitsi?datachannel@https://meet.ffmuc.net/olcrtc-xxxxxxxx#<64 hex HMAC>$olc",
  "uri_copy_hint": "СКОПИРУЙТЕ эту строку выше (кнопка 📋 Копировать) и вставьте в клиент...",
  "subscribe_url": "https://VPN.ru/api/v1/user/olcrtc/sub?token=AAAA...",
  "yaml_url": "https://VPN.ru/api/v1/user/olcrtc/yaml",
  "client_downloads": [
     {"platform": "Windows/macOS", "name": "OlcBox", "url": "https://github.com/alananisimov/olcbox/releases"},
     {"platform": "Android",         "name": "owenclave", "url": "https://github.com/owenewans/owenclave/releases"}
  ],
  "expired_at": 1789999999,
  "is_active": true
}
```
> ⚙️ **Jitsi-хост** в `uri` берётся из переменной `OLCRMGR_JITSI_SERVER` (default `https://meet.ffmuc.net`).
> Меняется только в `.env`/compose: `OLCRMGR_JITSI_SERVER=https://<ваш-jitsi>` + `docker compose up -d --force-recreate olcrtc-manager`.
> `room.id` (часть после `@`) — всегда **полный URL комнаты**, НЕ UUID. Хвостовая метка `$olc` — часть спецификации URI v1 и сохраняется бэкендом.

У каждого пользователя **СВОЯ УНИКАЛЬНАЯ** ссылка с персональным HMAC — выдача автоматическая через `plugins/OlcRTC/Services/OlcRTCManagerClient.php::getUserUri()`.
