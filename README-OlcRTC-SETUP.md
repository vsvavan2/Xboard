# Xboard + OlcRTC Integration — форк vsvavan2/Xboard

> Готовый стек VPN-панели на базе Xboard (PHP/Laravel + Vue) + интегрированный olcrtc-manager
> (Go-микросервис, поднимающий отдельный olcrtc-процесс на каждого пользователя).
> Оплата через ЮKassa: ПСБ (СБП), ЮMoney, Сбер, Тинькофф, карты МИР/Visa/MC.

---

## 🏗️ Архитектура одним взглядом

```
                        ┌──────────────────────────────────────────────┐
                        │              Docker Compose stack            │
                        │                                              │
   🌐 Internet :7001 ──►  xboard (Caddy → Octane PHP → Laravel/Vue)   │
                        │        │                                     │
                        │        │  Internal HTTP API (:8080)          │
                        │        ▼                                     │
                        │  olcrtc-manager (Go + Gin + SQLite)          │
                        │        │  fork_exec mode=srv                  │
                        │        ├─► olcrtc user #1 (room + key #1)    │
                        │        ├─► olcrtc user #2 (room + key #2)    │
                        │        └─► ...                               │
                        └──────────────────────────────────────────────┘
```

Сервисы общаются **внутренней docker-сетью** (порт 8080 manager-а не торчит наружу).
Внешний мир видит только Xboard на `:7001` (поставьте за Nginx/Caddy + Cloudflare для HTTPS).

---

## 🚀 Шаг 1. Как опубликовать этот форк на свой GitHub (`vsvavan2/Xboard`)

> Сделано один раз. Всё, что ниже — одна **локальная** папка `Xboard-src`.

```bash
# 1. Инициализируем репозиторий локально
cd Xboard-src/
git init
git checkout -b master

# 2. Добавляем ВЕСЬ код (плагин OlcRTC, менеджер, docker-compose и т.д.)
git add -A
git -c core.autocrlf=false commit -m "init: Xboard + OlcRTC integration (one-click stack)"

# 3. Привязываем к ТВОЕМУ пустому репозиторию на GitHub
#    (сначала создай пустой репозиторий https://github.com/new → vsvavan2/Xboard, без README!)
git remote add origin https://github.com/vsvavan2/Xboard.git
git push -u origin master
```

### 🔑 Важная настройка один раз: сделать GHCR-образы публичными

После первого `git push` GitHub Action **автоматически** соберёт два Docker-образа:
- `ghcr.io/vsvavan2/xboard:latest` — PHP/Laravel веб
- `ghcr.io/vsvavan2/xboard-olcrtc-manager:latest` — Go-менеджер

По умолчанию GHCR-образы **приватные**. Чтобы `docker pull` работал без логина на VPS:

1. Открой → https://github.com/users/vsvavan2/packages?repo_name=Xboard
2. По очереди открой каждый пакет (`xboard` и `xboard-olcrtc-manager`)
3. Правый верхний угол ⚙️ **Package settings** → внизу **Danger Zone** →
   **Change visibility** → **Public** → подтверди.

Готово — теперь任何人 сможет `docker pull ghcr.io/vsvavan2/xboard:latest`.

---

## ⚡️ Шаг 2. One-Click установка на свежий VPS (Ubuntu 22.04 / Debian 12)

Одна команда на VPS под root:

```bash
curl -fsSL https://raw.githubusercontent.com/vsvavan2/Xboard/master/install.sh | bash
```

Скрипт автоматически:
1. ✅ Ставит Docker + docker compose plugin (официальным скриптом get.docker.com)
2. ✅ Клонирует `vsvavan2/Xboard` → `/opt/xboard`
3. ✅ Генерирует `.env` со случайными `APP_KEY` и `OLCRMGR_API_KEY`
4. ✅ Пытается определить публичный IP и подставить в `APP_URL`
5. ✅ Запускает `php artisan xboard:install` — следуй вопросам (укажи email/пароль админа)
6. ✅ Делает `docker compose up -d`

Готово! Открой:

```
http://<IP-VPS>:7001
```

---

## 🛠️ Шаг 3. Настройка в админке Xboard

Логинишься созданным админом:

### 🔌 А) Включить плагин OlcRTC
`Плагины → OlcRTC Integration → Настроить`

| Поле                 | Значение                                  |
|----------------------|-------------------------------------------|
| URL olcrtc-manager   | `http://olcrtc-manager:8080`  (именно так, docker DNS) |
| API-ключ             | то, что сгенерировал install.sh в `.env` (`grep OLCRMGR_API_KEY /opt/xboard/.env`) |
| Провайдер            | `jitsi`  (рекомендуется, не требует токена) |
| Транспорт            | `datachannel`                             |
| DNS                  | `8.8.8.8:53` или `1.1.1.1:53`             |
| Триал (часов)        | `6`                                       |
| Включить тест. период | `☑️ да`                                   |

Нажми **Сохранить**, потом слайдер **Включить плагин** → вкл.

### 💳 Б) Подключить ЮKassa (оплата)
`Платёжки → Добавить платёжный метод → ЮKassa (YooKassa)`

| Поле            | Значение из ЛК ЮKassa                         |
|-----------------|-----------------------------------------------|
| Shop ID         | `Настройки → Магазин → ID магазина` (цифры)  |
| Секретный ключ  | `Настройки → API ключи → Секретный ключ`      |
| Методы оплаты   | `bank_card,sberbank,yoomoney,sbp,tinkoff_bank`|
| Capture         | `☑️ Автосписание`                             |
| Чек 54-ФЗ       | только если подключена онлайн-касса ЮKassa   |

**Вебхук ЮKassa:** в ЛК ЮKassa → `Настройки → HTTP-уведомления` → включи `payment.succeeded` и `payment.waiting_for_capture`, URL укажи:
```
https://твой.домен/api/v1/guest/payment/notify/yookassa
```
(для тестов без домена: используй `http://IP:7001/api/v1/guest/payment/notify/yookassa` и тестовый shop_id `548791`).

### 💼 В) Создать тариф
`Тарифы → Добавить план`:
- Название: `Месяц OlcRTC VPN`
- Цена (в **копейках**, рубли × 100): например `29900` = 299 ₽
- Период: `monthly` (30 дней), `quarterly`, `yearly` — как хочешь
- Сохрани → **Включить план**

Сервис готов к регистрации пользователей! 🎉

---

## 👥 Как это выглядит у пользователя

1. `https://твой.домен` → **Регистрация** (email + пароль)
2. Личный кабинет → появляется раздел **OlcRTC** (API доступен):
   ```
   GET /api/v1/user/olcrtc           → JSON: uri, yaml, subscribe_url, expires_at
   GET /api/v1/user/olcrtc/yaml      → скачать client.yaml
   GET /api/v1/user/olcrtc/sub?token=<user_token> → plain-text подписка sub.md
   ```
3. Сразу после регистрации **сам включается 6-часовой тест** (триал), olcrtc:// ссылка уже работает.
4. Заканчивается → **Купить** → выбирает СБП/ЮMoney/карту → редирект на ЮKassa → оплата → возврат на сайт.
5. Подписка продлена до `expired_at`, новая ссылка в ЛК — без Телеграм-бота и ручных действий.

---

## 🎨 Опционально: красивый Vue-компонент «OlcRTC вкладка» в ЛК

Если хочешь вместо голого API красивую страницу с кнопками **«Копировать URI»**, **«Скачать YAML»**, бейджем статуса и прогресс-баром подписки — добавь Vue-компонент в стандартную тему Xboard:

> Файл для добавления (если у тебя стандартная тема с Inertia.js):
> `resources/js/Pages/User/OlcRTC.vue`

Каркас такого компонента (скопируй → внедри в роутер темы):

```vue
<script setup>
import { ref, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'

const data = ref(null)
const copied = ref(false)
const err = ref('')

const fmtDate = ts => ts ? new Date(ts * 1000).toLocaleString('ru-RU') : '—'
const hoursLeft = () => data.value?.expired_at
    ? Math.max(0, Math.round((data.value.expired_at - Date.now()/1000) / 3600))
    : 0

onMounted(async () => {
  try {
    const r = await axios.get('/api/v1/user/olcrtc')
    data.value = r.data.data
  } catch (e) { err.value = e?.response?.data?.message || e.message }
})

const copyUri = async () => {
  await navigator.clipboard.writeText(data.value.uri)
  copied.value = true
  setTimeout(() => copied.value = false, 1500)
}
</script>

<template>
  <Head title="OlcRTC VPN" />
  <div class="max-w-3xl mx-auto p-6 space-y-5">
    <h1 class="text-2xl font-bold">🔐 OlcRTC VPN</h1>

    <div v-if="err" class="p-4 rounded-lg bg-red-50 text-red-700 border border-red-200">
      {{ err }}
    </div>
    <div v-else-if="!data" class="text-gray-500">Загрузка...</div>

    <template v-else>
      <!-- Статус -->
      <div class="p-5 rounded-xl shadow-sm border"
           :class="data.is_active && !data.banned
                   ? 'bg-emerald-50 border-emerald-200'
                   : 'bg-amber-50 border-amber-200'">
        <div class="flex items-center gap-3">
          <div class="text-3xl">
            {{ data.banned ? '🚫' : data.is_active ? '✅' : '⏳' }}
          </div>
          <div>
            <div class="font-semibold text-lg">
              {{ data.banned ? 'Аккаунт заблокирован'
                 : data.is_active ? 'Подписка активна' : 'Подписка истекла' }}
            </div>
            <div class="text-sm text-gray-600">
              Действует до: <b>{{ fmtDate(data.expired_at) }}</b>
              <span class="text-gray-400">(осталось ~{{ hoursLeft() }} ч)</span>
            </div>
          </div>
        </div>
      </div>

      <!-- URI -->
      <div class="p-5 rounded-xl shadow-sm border bg-white space-y-3">
        <div class="font-semibold">🔗 URI (вставить в OwenClave / OlcBox / Veil)</div>
        <div class="flex items-center gap-2">
          <code class="flex-1 p-3 bg-gray-900 text-green-300 rounded text-xs overflow-x-auto">
            {{ data.uri || '— пока нет, обратитесь в поддержку' }}
          </code>
          <button @click="copyUri"
                  class="px-4 py-2 rounded-lg font-medium text-white transition"
                  :class="copied ? 'bg-emerald-500' : 'bg-indigo-600 hover:bg-indigo-700'">
            {{ copied ? '✓ Скопировано' : '📋 Копировать' }}
          </button>
        </div>
        <a :href="data.subscribe_url"
           class="inline-flex items-center gap-2 text-indigo-600 hover:underline text-sm">
          📡 Ссылка на автообновляемую подписку (sub.md)
        </a>
      </div>

      <!-- YAML -->
      <div class="p-5 rounded-xl shadow-sm border bg-white space-y-3">
        <div class="font-semibold">📄 Конфиг client.yaml (для CLI-клиента olcrtc)</div>
        <a href="/api/v1/user/olcrtc/yaml"
           class="inline-block px-4 py-2 bg-slate-800 text-white rounded-lg hover:bg-slate-900 text-sm">
          ⬇️ Скачать client.yaml
        </a>
      </div>

      <!-- Купить -->
      <div v-if="!data.is_active || hoursLeft() < 72"
           class="p-5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white">
        <div class="flex items-center justify-between">
          <div>
            <div class="font-semibold text-lg">Продлить подписку</div>
            <div class="text-sm text-indigo-100">От 299 ₽ / месяц • СБП / ЮMoney / любые карты</div>
          </div>
          <button @click="router.get('/user/plan')"
                  class="px-5 py-2.5 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50">
            💳 Выбрать тариф →
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
```

Затем в роутере фронтенда (`resources/js/routes/user.js` или где у тебя тема) добавь:
```js
{ path: '/user/olcrtc', name: 'user.olcrtc', component: () => import('@/Pages/User/OlcRTC.vue'), meta: { title: 'OlcRTC' } },
```
и в `app/Http/Routes/V1/UserRoute.php` добавь веб-роуту, чтобы Inertia вернула страницу.

---

## 🧰 Полезные команды на VPS

```bash
cd /opt/xboard

# Статус контейнеров
docker compose ps

# Логи Xboard (Laravel)
docker compose logs xboard --tail=100 -f

# Логи olcrtc-manager
docker compose logs olcrtc-manager --tail=100 -f

# Логи отдельно взятого VPN-инстанса (внутри контейнера manager)
docker exec xboard-olcrtc-manager ls /var/lib/olcrtc-manager/instances/
docker exec xboard-olcrtc-manager cat /var/lib/olcrtc-manager/instances/<id>.log

# Перезапуск всего стека
docker compose restart

# Обновление до последней версии с GitHub
git pull
docker compose pull
docker compose up -d
```

---

## ❓ FAQ и частые проблемы

**1. olcrtc бинарник не найден внутри контейнера manager**
Если в логах `exec /usr/local/bin/olcrtc: no such file or directory` — значит GitHub Release openlibrecommunity/olcrtc не был найден при сборке. Исправление:
- собери `olcrtc` руками на хосте → положи в `/usr/local/bin/olcrtc`
- в `docker-compose.yml` раскомментируй строчку:
  ```yaml
  volumes:
    - /usr/local/bin/olcrtc:/usr/local/bin/olcrtc:ro
  ```
- `docker compose up -d --force-recreate olcrtc-manager`

**2. Контейнер manager не может запустить процесс /dev/net/tun отсутствует**
Убедись:
- на хосте есть `ls /dev/net/tun` (если нет → `modprobe tun`)
- в `docker-compose.yml` прописаны `devices: [/dev/net/tun:/dev/net/tun]` и `cap_add: [NET_ADMIN, NET_RAW]`

**3. ЮKassa вебхук не приходит / не работает оплата**
- Проверь, что `APP_URL` в `.env` — настоящий https-домен (а не `http://localhost`)
- В ЛК ЮKassa включи "Тестовый режим" сначала и проверь с `shop_id=548791` и тестовыми картами из документации
- Посмотри логи: `docker compose logs xboard 2>&1 | grep -i yookassa`

**4. Триал не создаётся после регистрации**
- Проверь: плагин **включён** (слайдер вкл)
- В настройках плагина стоит `☑️ trial_enabled`, `trial_hours > 0`
- URL и API-ключ olcrtc-manager корректны (для docker-композа — `http://olcrtc-manager:8080`)
- `docker compose logs olcrtc-manager` — смотри ошибки

---

## 📦 Что лежит в репозитории

| Файл / директория      | Назначение                                                               |
|------------------------|--------------------------------------------------------------------------|
| `Dockerfile`           | Сборка веб-образа Xboard (PHP 8.2 + Swoole + Octane + Caddy). Код берётся **из репозитория**, не с upstream cedar2025. |
| `olcrtc-manager.Dockerfile` | Сборка Go-менеджера + скачивание бинарника `olcrtc` с GitHub Releases openlibrecommunity/olcrtc |
| `docker-compose.yml`   | Единый стек: веб + manager, internal-сеть, volumes (redis, olcrmgr-data) |
| `.env.olcrtc.example`  | Шаблон окружения с преднастроенными OlcRTC-переменными                   |
| `install.sh`           | One-Click скрипт установки на VPS одной `curl | bash`                    |
| `.github/workflows/docker-publish.yml` | CI: при пуше в master собирает и пушит **два** мультиарх образа (amd64 + arm64) в GHCR |
| `plugins/OlcRTC/`      | Плагин Xboard: хуки регистрации/оплаты, ЮKassa драйвер, API endpoints для ЛК |
| `olcrtc-manager/`      | Go-микросервис: REST API Gin + SQLite, супервизор процессов olcrtc mode=srv на каждого пользователя |

---

Сделано с ❤️ для self-hosted VPN без Телеграм и с веб-кабинетом.
