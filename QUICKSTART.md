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

## 2. 🔄 Как обновить панель до последней версии (1 команда)
✅ **Все ваши данные сохраняются**: `.env`, SQLite DB, пользователи, заказы, настройки платежей.
Скопируй и выполни на VPS:

```bash
cd /opt/xboard && sudo cp -a .env .env.BACKUP-$(date +%Y%m%d_%H%M) && git fetch origin && git reset --hard origin/master && docker compose down --remove-orphans 2>/dev/null; chmod +x install.sh && sudo -E bash install.sh
```

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
1. **ЮKassa**: Личный кабинет ЮKassa → Магазин → скопируй **shopId** (например `123456`) + **Секретный ключ** (начинается с `live_` или `test_`)
2. **ЮMoney**: Кошелёк номер (`XXXX XXXX XXXX XXXX`) + `client_id` + `client_secret` → https://yoomoney.ru/myservices/new

### 3.2 ⚡ КОМАНДА «ВСТАВЬ КЛЮЧИ И ГОТОВО» (копируй целиком на VPS)
Команда спросит **shop_id** (вводишь цифрами, Enter) и **секретный ключ** (ввод скрытый, копируй-повляй). Если ничего не вводить → останется demo.

```bash
cd /opt/xboard && echo '=== Настройка реальных платежей: ЮKassa + ЮMoney ===' && read -p 'YOOKASSA_SHOP_ID (например 548791 или Enter=demo): ' YID && read -sp 'YOOKASSA_SECRET_KEY (скрытый ввод, копируй и вставляй): ' YSEC && echo '' && sudo cp -a .env .env.PAYMENTS-BACKUP-$(date +%H%M) && sed -i "/^YOOKASSA_SHOP_ID=/c\YOOKASSA_SHOP_ID=${YID:-548791}" .env && sed -i "/^YOOKASSA_SECRET_KEY=/c\YOOKASSA_SECRET_KEY=${YSEC:-test_SecretKey}" .env && sed -i '/^YOOMONEY_WALLET=/c\YOOMONEY_WALLET=4100119445638573' .env && sed -i '/^YOOMONEY_CLIENT_ID=/c\YOOMONEY_CLIENT_ID=1626E206' .env && sed -i '/^YOOMONEY_CLIENT_SECRET=/c\YOOMONEY_CLIENT_SECRET=5C1AF' .env && echo '✅ Ключи сохранены в .env. Пересобираем xboard-web контейнер...' && docker compose up -d --force-recreate xboard-web && sleep 20 && echo '' && echo '🎉 ГОТОВО! Реальные платежи ЮKassa / ЮMoney активны.' && echo '👉 Проверь в админке → «Платежи» → статус ЮKassa = ВКЛЮЧЕНА.'
```

### 3.3 🌐 Webhook ЮKassa (ЧТОБЫ ПЛАТЕЖИ ПРИХОДИЛИ АВТОМАТИЧЕСКИ)
1. Открой админку → **Платежи** → справа от ЮKassa нажми **Редактировать**
2. Скопируй поле **«Webhook URL»** целиком (выглядит как `https://ip/api/v1/guest/payment/notify/yookassa/uuid`)
3. Открой ЛК ЮKassa → **HTTP-уведомления** → вставь скопированный URL → сохрани

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
2. Выбери тариф (30 / 90 / 365 дней) → **Оплати через ЮKassa** → дождись статуса «Оплачен»
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
- ✅ Выдача ключей `olcrtc://jitsi?datachannel@ROOM#HASH$olc`
- ✅ Виджет с ключом **на главной Панели управления** (сразу после покупки)
- ✅ ЮKassa / ЮMoney (оплата картами, СБП, Сбер)
- ✅ 3 тарифа (Базовый 30д · Профи 90д · Максимум 365д)
- ✅ 6 статей Базы знаний (пошаговые подключения для OlcBox/owenclave)
- ✅ Бесплатный **тест 6 часов** (1 раз на аккаунт)
- ✅ Матрично-зелёный хакерский дизайн + анимированные фейковые счётчики

---

> ⚠️ **ВАЖНО**: СМЕНИТЕ АДМИН-ПАРОЛЬ ИЗ `Admin123456` НА СВОЙ СРАЗУ ПОСЛЕ ПЕРВОГО ВХОДА (меню профиля справа → «Сменить пароль»).
> ⚠️ **НИКОГДА НЕ КОММИТЬ ФАЙЛ `.env` В GITHUB**. Он содержит APP_KEY / OLCRMGR_API_KEY / платёжные секреты.
