# TROUBLESHOOTING.md — Диагностика и исправления (vsvavan2/Xboard)

> Все баги ниже **уже исправлены** в коммитах `175fdfe` → `e40731a` → `c030eed` → `49fd0db` → `0bc7a90` → `f1ed74c`.
> Документ описывает **КАК ОНИ ВЫГЛЯДЯТ**, **КАК ИХ ДИАГНОСТИРОВАТЬ** и **КАК ИСПРАВИТЬ ВРУЧНУЮ** —
> вдруг вы откатили коммит, или баг проявился на fork-варианте.

---

## 🧭 Оглавление

| #   | Симптом                                                                           | Коммит-фикс |
|-----|-----------------------------------------------------------------------------------|-------------|
| T01 | `Plugin not found: olc_rtc` (acronym case mismatch)                               | `175fdfe`   |
| T02 | `ghcr.io: denied / unauthorized` → образы не pull-ятся                            | `175fdfe`   |
| T03 | Ошибки require/parse Plugin.php → **тишина** (в install.sh нет вывода)            | `175fdfe`   |
| T04 | `INSTALLED=true` в `.env` → плагины больше никогда не устанавливаются            | `e40731a`   |
| T05 | `STDERR` не defined (web-SAPI не CLI) → Warning 500                              | `e40731a`   |
| T06 | `install.sh entrypoint "bash -lc"`: `exec: bash: not found` (Alpine image)       | `c030eed`   |
| T07 | Веб-запрос enable → generic "Plugin not found" без деталей                        | `c030eed`   |
| T08 | `docker-compose.yml` — пропущен volume plugins-core → плагины ядра потеряны      | `c030eed`   |
| T09 | **loadPlugin() удалял БД-запись плагина** при первом File::exists fail (data-loss)| `c030eed`   |
| T10 | `UNIQUE constraint failed: v2_user.email` → registerAdmin падает                  | `49fd0db`   |
| T11 | Artisan `xboard:install` **ВСЕГДА exit=0** (даже при Exception)                  | `49fd0db`   |
| T12 | Плагин olc_rtc **всегда enabled=0** (пост-проход enable пропускался)              | `49fd0db`   |
| T13 | Fatal error в use-statements Plugin.php (PSR-4 class not found) → loadPlugin null | `49fd0db`   |
| T14 | Headless artisan prompts/YesNo зависает CI                                        | `49fd0db`   |
| T15 | Скрипт отработал, `:7001` **ERR_EMPTY_RESPONSE** (пропущен `docker compose up`)  | `0bc7a90`   |
| T17 | `docker compose pull` → `denied` для `ghcr.io/.../xboard-olcrtc-manager`          | —           |
| T18 | Кнопка «Установить» плагина → 404 (bind-mount `./plugins` перекрыл образ)         | —           |
| T19 | В админке остались китайские иероглифы (zh-CN в React-бандле)                     | —           |
| T20 | **Ключ выдан, но клиент «не подключается»** (room.id = UUID вместо URL)           | `f1ed74c`   |
| T21 | Сборка `olcrtc-manager` падает: `Remote branch latest not found` / curl 404        | —           |
| T22 | Сайт/админка 500: `no such table: v2_plugins` (база установлена частично)          | —           |
| T23 | Ключ `olcrtc://…` корректен, но **не подключается** (srv не стартует: флаг `-c`)   | —           |
| T24 | `client.yaml` и ссылка-подписка скачиваются битыми (`%!(EXTRA string=…)`)          | —           |
| T25 | После рестарта/пересборки кабинет пуст (нет ключа и ссылки), `/api/olcrtc/*` → 401 | `067a972`   |
| T26 | Обновили ядро `openlibrecommunity/olcrtc`, а версия не изменилась (Docker build-cache) | —        |
| T27 | ЮMoney: при оплате редирект на `quickpay/error?reason=referer` («переводы недоступны») | —       |
| T28 | В админке/ЛК цены в юанях (`¥` / `元`, валюта `CNY`) вместо рублей                     | —       |
| T29 | Нет HTTPS (:443) → ЮKassa не доставляет вебхуки («уведомления не приходят»)          | —       |

---

---

## T01. `Plugin not found: olc_rtc` — acronym case mismatch

### Симптом
```
Плагины → OlcRTC Integration → Включить → Ошибка: Plugin not found: olc_rtc
```
или в логах artisan:
```
[WARN][PluginManager] Plugin class file not found: /www/plugins/olc_rtc/Plugin.php
```

### Root-cause
- Код: `code = olc_rtc` (snake_case, `_` разделитель)
- Директория на диске: `plugins/OlcRTC/` (Pascal per-segment: Olc + RTC = `OlcRTC`)
- `Str::studly('olc_rtc')` = `OlcRtc` (RTC lowercase!)
- На Linux (case-sensitive ext4) `/www/plugins/OlcRtc/` ≠ `/www/plugins/OlcRTC/`.

### Диагностика (1 команда)
```bash
cd /opt/xboard
docker compose exec xboard ls -la /www/plugins /www/plugins-core 2>&1 | grep -i 'rtc\|olc'
```
Ожидаем: видите **OlcRTC/**, но **не** видите OlcRtc/.

### Временный фикс руками (если не хотите обновлять коммиты)
```bash
cd /opt/xboard/plugins
# Создайте symlink (если поддерживается FS). Если нет — рекурсивную копию:
cp -r OlcRTC OlcRtc
docker compose restart xboard-web
```

### Правильный фикс
Обновите форк до коммита `175fdfe` или новее.

**Механизм фикса**: `resolvePluginPath()` теперь генерирует **декартово произведение**
4 вариантов регистра на каждый snake_case-сегмент (`ucfirst`/`upper`/`lower`/`raw`) и
проверяет case-insensitive через `scandir`.

---

## T02. `ghcr.io: denied / unauthorized` → образы не pull-ятся

### Симптом
```
[+] Pull-им Docker образы (redis / olcrtc-manager)...
Error response from daemon: error from registry: denied
denied
```

### Root-cause
GitHub Package Registry (GHCR) по умолчанию создаёт пакеты **приватные**.

### Диагностика
Откройте:
```
https://github.com/users/vsvavan2/packages?repo_name=Xboard
```
Если виден замок 🔒 → пакет приватный.

### Фикс
1. Откройте пакет `ghcr.io/vsvavan2/xboard` → `Package settings` → `Change visibility` → **Public**
2. То же самое для `ghcr.io/vsvavan2/xboard-olcrtc-manager`.

### Workaround — локальная сборка (всегда работает, install.sh так и делает)
```bash
cd /opt/xboard
docker compose build xboard --no-cache
docker compose build olcrtc-manager --no-cache
```

---

## T03. Ошибки require/parse Plugin.php → тишина (no STDERR output из artisan/CI)

### Симптом
installDefaultPlugins() говорит "плагины установлены", но часть плагинов отсутствует в БД. В хранилище storage/logs laravel-error.log ничего нет.

### Root-cause
`require_once` внутри try → `catch` писал только в `Log::warning()`.

### Диагностика
```bash
cd /opt/xboard
# Запускаем installDefaultPlugins явно с выводом warnings в STDERR:
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='
    \App\Services\Plugin\PluginManager::installDefaultPlugins();
    echo \"DONE\n\";
  '"
```

### Фикс
Обновитесь до `175fdfe`: теперь catch Throwable пишет **и** `storage/logs`, **и** `fwrite(STDERR)` с
`E_USER_WARNING` через `trigger_error` (появляется и в display_errors CLI).

---

## T04. `.env` имеет `INSTALLED=true` → плагины больше никогда не устанавливаются

### Симптом
Переустанавливаете скрипт (чистите `.env` только частично). Раздел «установка плагинов» всегда пустой.
```
[entrypoint] Skipping xboard:update (not yet installed or running xboard:install).
```
Но плагин OlcRTC в БД `enabled=0` и не появляется в админке.

### Root-cause
Команда `XboardInstall::handle()` в начале проверяла:
```php
if (env('INSTALLED')) return Command::SUCCESS;
```
→ **ВСЯ** логика migrate, registerAdmin, installDefaultPlugins пропускалась сразу.

### Диагностика
```bash
cd /opt/xboard
grep '^INSTALLED' .env
# INSTALLED=true
```

### Фикс руками
```bash
cd /opt/xboard
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='\App\Services\Plugin\PluginManager::installDefaultPlugins();'"
```

### Правильный фикс
Обновитесь до `e40731a`. Теперь `INSTALLED=true` **не** блокирует migrate/installDefaultPlugins —
команда стала полностью идемпотентной.

---

## T05. `STDERR` not defined (web-SAPI) → Warning 500

### Симптом
В логах xboard-web (Caddy+Octane) вы видите:
```
Use of undefined constant STDERR - assumed 'STDERR'
```
или HTTP 500 на POST `/api/v2/admin/plugin/enable`.

### Root-cause
Константа `STDERR` определена **только** в CLI-SAPI (php-cli). В FPM / Octane (web-SAPI) она `undefined`.
Старый код: `fwrite(STDERR, ...)` → PHP Warning.

### Диагностика
```bash
cd /opt/xboard
docker compose logs --tail 50 xboard-web | grep -i 'STDERR\|undefined constant'
```

### Фикс
Обновитесь до `e40731a`. Теперь запись в STDERR безопасна:
```php
if (defined('STDERR') && is_resource(STDERR)) $stderr = STDERR;
else $stderr = @fopen('php://stderr','w');
... fwrite($stderr, ...); @error_log($msg);
```

---

## T06. `install.sh entrypoint "bash -lc"`: `exec: bash: not found`

### Симптом
install.sh секция 5 "Повторно запускаем installDefaultPlugins" → пустой вывод `PLUGINS_DONE` нет,
а при ручном запуске:
```
OCI runtime exec failed: exec failed: unable to start container process: exec: "bash": executable file not found in $PATH
```

### Root-cause
Образ xboard построен на `phpswoole/swoole:php8.2-alpine`. Alpine использует `busybox sh`, `bash` не включён.

### Диагностика
```bash
cd /opt/xboard
docker compose exec xboard which bash    # пустой вывод
docker compose exec xboard which sh      # /bin/sh ✅
```

### Фикс руками
Замените `bash -lc` на `sh -lc` в командах install.sh или используйте:
```bash
docker compose run --rm --entrypoint "sh -lc" xboard 'echo hello'
```

### Правильный фикс
Обновитесь до `c030eed`: все 3 места install.sh (`entrypoint "bash -lc"` → `"sh -lc"`).

---

## T07. Web enable → generic "Plugin not found" без деталей

### Симптом
Клик «Включить» плагин → error toast:
```
Ошибка сервера
Plugin not found: olc_rtc
```
Больше **никакой** информации.

### Root-cause
`PluginManager::enable()` бросал `throw new \Exception('Plugin not found: ' . $code)`. Без diagnostics.

### Диагностика
Смотрим laravel.log:
```bash
cd /opt/xboard
docker compose exec xboard cat storage/logs/laravel-$(date +%Y-%m-%d).log | tail -80
```

### Фикс
Обновитесь до `c030eed`: теперь Exception содержит **13 полей диагностики**:
```
Plugin not found: olc_rtc
  code=olc_rtc namespace=Plugin\OlcRtc
  expectedPluginFile=/www/plugins/OlcRtc/Plugin.php (exists=NO)
  resolvedDir=/www/plugins/OlcRTC (configExists=YES)
  base_path=/www  cwd=/www
  scan(plugins)=OlcRTC/, Telegram/, MGate/, EPay/
  scan(plugins-core)=AlipayF2f/, Btcpay/, ...
  matchedCandidates=/www/plugins/OlcRTC/Plugin.php
```
→ сразу видно: namespace case не совпадает с названием папки.

---

## T08. docker-compose.yml — пропущен volume plugins-core

### Симптом
`plugins-core/` плагины (core: alipay, coinbase, telegram...) есть в image, но при перезапуске контейнера
обновлённая локальная правка не видна.

### Root-cause
В docker-compose.yml был volume mount **только** для `plugins/`, для `plugins-core/` — нет:
```yaml
volumes:
  - ./plugins:/www/plugins    # ✅ было
  # plugins-core — ❌ отсутствовал!
```

### Диагностика
```bash
cd /opt/xboard
docker compose exec xboard ls -la /www/plugins-core 2>&1
```
→ содержимое берётся **из образа**, а не с хоста `/opt/xboard/plugins-core/`.

### Фикс
Обновитесь до `c030eed`. Теперь compose прописывает:
```yaml
- ./plugins-core:/www/plugins-core:ro
```
(Монтируем read-only, т.к. правки core делаются на хосте в Git).

---

## T09. loadPlugin() удалял БД-запись при первом File::exists fail

### Симптом
Первый запрос (container start) volume plugins ещё не прогрелся → `File::exists(Plugin.php)=false`.
Старая логика: **удаляла запись из v2_plugins** (data-loss!).

```php
// СТАРЫЙ КОД: плагин удалён из БД НАВСЕГДА
Plugin::where('code',$code)->delete();
```

### Диагностика
```bash
cd /opt/xboard
apt-get install sqlite3 2>/dev/null
sqlite3 ./.docker/.data/xboard.sqlite "SELECT code,enabled,is_enabled FROM v2_plugins;"
# В списке нет alipay_f2f / telegram... — удалены.
```

### Фикс руками
```bash
cd /opt/xboard
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='\App\Services\Plugin\PluginManager::installDefaultPlugins();'"
```
→ плагины back в БД.

### Правильный фикс
Обновитесь до `c030eed`. Теперь `File::exists=false` → **только diagnostics в лог**, `return null` (БД НЕ трогаем).

---

## T10. `UNIQUE constraint failed: v2_user.email` → registerAdmin падает

### Симптом
Частичная переустановка (удалили `.env` для чистого старта, но **оставили sqlite БД**):
```
正在注册管理员账号
PDOException: SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: v2_user.email
in /www/vendor/laravel/framework/src/Illuminate/Database/Connection.php:584
```

### Root-cause
Метод `registerAdmin()` пытался `User::create()` → второй `admin@example.com` в БД.

### Диагностика
```bash
cd /opt/xboard
sqlite3 ./.docker/.data/xboard.sqlite "SELECT id,email,is_admin FROM v2_user;"
```
Видите строку `admin@example.com` уже есть.

### Фикс руками
```bash
sqlite3 ./.docker/.data/xboard.sqlite "
  UPDATE v2_user SET password='\$2y\$10\$...hash...' WHERE email='admin@example.com';
"
```
или удалите admin и перезапустите install.

### Правильный фикс
Обновитесь до `49fd0db`. Теперь `registerAdmin()` идемпотентна:
1. `User::where(email)->first()` → **существует**? → **update password**;
2. Если email занят **не**-админом? → генерируем случайный `admin-<rnd6>@example.com`;
3. И только иначе `create()`.

---

## T11. Artisan `xboard:install` ВСЕГДА exit=0

### Симптом
install.sh пишет:
```
if docker compose run ... xboard php artisan xboard:install; then
  echo "✅ Установлен"; else echo "❌ Провал"; fi
```
Всегда печатает `✅` даже при `UNIQUE` crash выше.

### Root-cause
```php
// СТАРЫЙ КОД: НЕТ return → exit 0 implicit
catch (\Exception $e) { $this->error($e); }
```

### Диагностика
```bash
cd /opt/xboard
docker compose run --rm xboard php artisan xboard:install
echo "artisan exit=$?"
# Раньше был 0 даже при Exception!
```

### Фикс
Обновитесь до `49fd0db`:
```php
catch (\Exception $e) { $this->error($e); return 1; }
return 0;
```

---

## T12. Плагин olc_rtc всегда `enabled=0`

### Симптом
В логах install.sh финал:
```
[+] ✅ Плагин OlcRTC ОТЛИЧНО — есть в v2_plugins: olc_rtc|enabled=0|v1.0.0
```
Хотите `enabled=1` (включён сразу).

### Root-cause
`installDefaultPlugins()` проход по директориям:
```php
if (Plugin::where('code', $code)->exists()) continue;  // ⚠️ ПРОПУСК
```
→ повторный запуск **не вызывает enable()**, даже если код в always-enable списке!

### Диагностика
```bash
sqlite3 ./.docker/.data/xboard.sqlite "SELECT code,is_enabled FROM v2_plugins WHERE code='olc_rtc';"
# olc_rtc|0
```

### Фикс руками
```bash
cd /opt/xboard
docker compose run --rm --entrypoint "sh -lc" xboard \
  "php artisan tinker --execute='\App\Services\Plugin\PluginManager::enable(\"olc_rtc\");'"
```

### Правильный фикс
Обновитесь до `49fd0db`: после основного прохода работает **ПОСТ-ПРОХОД** (new step):
- Собирает все `forceEnable=true` + `alwaysEnableCodes`
- Для каждого кода в БД где `is_enabled=0` → явно `$pluginManager->enable($code)`
→ теперь olc_rtc имеет `enabled=1` сразу после **первого** запуска install.

---

## T13. Fatal error в use-statements Plugin.php (PSR-4 class not found) → loadPlugin null

### Симптом
Включение плагина → Exception с diagnostics:
```
expectedPluginFile=/www/plugins/OlcRTC/Plugin.php (exists=YES)
plugin_namespace=Plugin\OlcRTC
```
File существует, но `class_exists(Plugin\OlcRTC\Plugin, false)` = false даже после require.

### Root-cause
Plugin.php содержит:
```php
<?php
namespace Plugin\OlcRTC;

use Plugin\OlcRTC\Payments\YooKassaPayment;   // ⚠️ Это использует PSR-4 autoload при загрузке!
use Plugin\OlcRTC\Services\OlcRTCManagerClient;

class Plugin extends AbstractPlugin { ... }
```
Если вложенный класс (`YooKassaPayment`) имеет **неверный namespace**, или **файл в неправильной подпапке**,
или composer classmap не знает о нём — PHP выбрасывает **E_COMPILE_ERROR/fatal** во время `require_once Plugin.php`.
Autoloader lookup завершился неудачно → скрипт не работает.

### Диагностика
```bash
cd /opt/xboard
# Проверяем вложенные PHP-файлы напрямую через PHP syntax:
docker compose exec xboard sh -lc "
  for f in \$(find /www/plugins/OlcRTC -name '*.php'); do
    php -l \$f 2>&1 | grep -v 'No syntax errors' && echo \"SYNTAX ERROR: \$f\";
  done
"
# Ещё проверяем composer autoload карту:
docker compose exec xboard php -r "
  require '/www/vendor/autoload.php';
  var_dump(class_exists(Plugin\OlcRTC\Payments\YooKassaPayment::class,false));
"
```

### Фикс руками
```bash
cd /opt/xboard
docker compose exec xboard composer -d=/www dump-autoload --optimize --classmap-authoritative
```

### Правильный фикс
Обновитесь до `49fd0db`:
1. `PluginManager::loadPlugin()` перед `require_once Plugin.php` делает **RecursiveIteratorIterator** всей папки
   плагина и `require_once` **КАЖДОГО** вложенного `.php` (Payments, Services, Controllers, Providers)
   с индивидуальным try/catch Throwable. → Сломанный helper-файл **больше не блокирует** класс Plugin.
2. `Dockerfile` после `composer install` запускает:
   ```dockerfile
   composer dump-autoload --optimize --classmap-authoritative
   ```

---

## T14. Headless artisan prompts зависают CI

### Симптом
`docker compose run` с AUTO_INSTALL зависает на `Do you really wish to run this command? (yes/no) [no]`.

### Root-cause
Laravel `migrate --force`/`db:wipe` имеют YesNo интерактивный confirm; APP_ENV=testing выводит вопросы.

### Диагностика
Лог install.sh зависает на 10+ минут после миграций.

### Фикс
Обновитесь до `49fd0db`. `install.sh` теперь запускает:
```bash
docker compose run --rm \
  -e APP_ENV=production \
  -e AUTO_INSTALL=1 \
  xboard php artisan xboard:install --no-interaction
```

---

## T15. Скрипт отработал, но `:7001` ERR_EMPTY_RESPONSE

### Симптом
В логах install.sh вы уже видите:
```
[+] Все коды плагинов в БД: olc_rtc ...
```
Но curl или браузер даёт:
```
GET / HTTP/1.1
* Empty reply from server
ERR_EMPTY_RESPONSE
```

### Root-cause (самая частая — T15-A)
install.sh работает 40–60 секунд: Section 4 → Section 5 → Section 6 (`docker compose up -d`).
Если пользователь нажал **Ctrl+C сразу после Section 5.1** (плагины проверены), — Section 6 **никогда не выполняется**,
контейнер `xboard-web` не создан → на `:7001` никто не слушает.

### Root-cause (T15-B, менее частая)
Section 5 несущественно упала и вернула ненулевой код (например `tinker --execute` exit=1) → bash
`if ...; then ... fi` обошёл секцию `docker compose up -d`.

### Диагностика
```bash
cd /opt/xboard
docker compose ps
# Ожидаем: xboard-web — Up. Если нет → T15 confirmed.
```

### Фикс руками (копи-паста)
```bash
cd /opt/xboard
docker compose up -d 2>&1 | tail -10

sleep 30
curl -sv --max-time 10 http://127.0.0.1:7001/ -o /dev/null 2>&1 | tail -15
```

### Правильный фикс
Обновитесь до `0bc7a90`:
1. Section 5 (plugin recheck) и Section 6 (up -d) обёрнуты в `|| true`. Даже если ^C → Section 6 выполнится.
2. Section 6 запускает цикл: 30 секунд каждые 5s показывает `docker compose ps` (видно state `health: starting → healthy`).
3. Section 6 делает 4 пробы `curl localhost:7001/` (retry на 502/503).
4. Финальный баннер всегда показывает **HTTP-код** и прямую ссылку 1 клик `http://<PUBLIC_IP>:7001/<secure_path>`.
5. Предоставляет 3-строчный recipe для ERR_EMPTY: `cd /opt/xboard && docker compose ps && docker compose logs --tail 80 xboard-web`.

---

---

## 🩺 Универсальный диагностический блок (любого непонятного бага — запускайте первым)

Если что-то не работает — скопируйте и запустите **на VPS**:

```bash
cd /opt/xboard
echo "============================================================"
echo " [1/6] CONTAINERS"
echo "============================================================"
docker compose ps
echo ""
echo "============================================================"
echo " [2/6] PORTS (xboard-web на :7001?)"
echo "============================================================"
ss -ltnp 2>/dev/null | grep 7001 || netstat -ltnp 2>/dev/null | grep 7001 || echo "ss/netstat не доступны — пропускаем"
echo ""
echo "============================================================"
echo " [3/6] PLUGINS в БД (codes + is_enabled)"
echo "============================================================"
(apt-get install -y sqlite3 >/dev/null 2>&1 || true)
sqlite3 ./.docker/.data/xboard.sqlite "SELECT code,is_enabled,version FROM v2_plugins;" 2>/dev/null || docker compose run --rm --entrypoint "sh -lc" xboard "php artisan tinker --execute='echo implode(chr(10),DB::select(DB::raw(\"SELECT code||\\\"|\\\"||is_enabled||\\\"|\\\"||version FROM v2_plugins\"))->pluck(\\\"*\\\");');"
echo ""
echo "============================================================"
echo " [4/6] LOG xboard-web — 80 lines"
echo "============================================================"
docker compose logs --tail 80 xboard-web 2>&1 | tail -85
echo ""
echo "============================================================"
echo " [5/6] LOG olcrtc-manager — 80 lines"
echo "============================================================"
docker compose logs --tail 80 olcrtc-manager 2>&1 | tail -85
echo ""
echo "============================================================"
echo " [6/6] HTTP-CODE / (localhost:7001)"
echo "============================================================"
curl -sS -o /dev/null -w "HTTP_CODE: %{http_code}\nTIME_TOTAL: %{time_total}s\n" --max-time 10 http://127.0.0.1:7001/ 2>&1 || echo "CURL FAIL"
echo ""
```

Отправьте вывод в GitHub Issue / чат поддержки — **почти все баги детерминируются по этому выводу**.

---

### T17. `docker compose pull` → `denied` для `ghcr.io/vsvavan2/xboard-olcrtc-manager:latest`

**Причина**: GitHub Packages GHCR по умолчанию создаёт образы **PRIVATE**, а не PUBLIC. Только пакет `xboard` вы сделали public, а `xboard-olcrtc-manager` остался приватным. Docker на VPS без `docker login ghcr.io` не может скачать приватный образ.

**Исправление (3 клика в браузере**:

1. Откройте: **https://github.com/users/vsvavan2/packages?repo_name=Xboard** (логин vsvavan2).
2. В списке кликните по **`xboard-olcrtc-manager`**.
3. Справа вверху ⚙️ **Package settings** → пролистайте до самого низа → **Danger Zone** → **🔒 Change package visibility** → выберите **🌐 Public** → подтвердите вводом `xboard-olcrtc-manager`.

**Временный обходной путь (без GHCR)**: соберите образ локально на VPS (работает всегда, даже если GitHub упал):**
```bash
cd /opt/xboard
docker compose build xboard --no-cache        # ~5-8 мин на VPS 2 ядра
docker compose build olcrtc-manager --no-cache  # ~1-2 мин
docker compose up -d
```

---

### T18. Карточка плагина есть, кнопка «Установить» → 404 Not Found / «Ошибка установки плагина: not found

**Причина**: `compose.yaml` монтирует ПУСТУЮ хостовую директорию `./plugins` (на VPS `/opt/xboard/plugins`) по пути `/www/plugins` ВНУТРИ контейнера. Bind-mount хоста ПОЛНОСТЬЮ ПЕРЕКРЫВАЕТ `/www/plugins` из образа Docker, где лежали плагин OlcRTC. В итоге: файлы `plugins/OlcRTC/Plugin.php внутри контейнера отсутствуют → 404.**

**Self-healing в новых образах (>=2026-09-12)**:
Entrypoint сам восстанавливает отсутствующие плагины из `/www/.image-src/` снапшота при каждом старте контейнера. Достаточно перезапустить контейнер:
```bash
cd /opt/xboard
docker compose restart xboard-web
docker compose logs --tail 30 xboard-web 2>&1 | grep -E "T18|materialis"
```
Ожидаемые строки в логах: `T18: restoring missing /www/plugins/OlcRTC from image snapshot`.

**Ручной фикс (для старых образов):
```bash
cd /opt/xboard
# Если вытаскиваем ОДИН ФАЙЛ, если у вас нет доступа к ghcr.io — просто клонируем реп и копируем
git clone --depth=1 https://github.com/vsvavan2/Xboard.git /tmp/xboard-src 2>/dev/null
cp -a /tmp/xboard-src/plugins/* /opt/xboard/plugins/
cp -a /tmp/xboard-src/plugins-core/* /opt/xboard/plugins-core/ 2>/dev/null
cp -a /tmp/xboard-src/theme/* /opt/xboard/theme/ 2>/dev/null
docker compose restart xboard-web
```

**Постоянное устранение root cause: уберите bind-mount `./plugins:/www/plugins` из `compose.yaml`, если вы не разрабатываете плагины локально. Достаточно оставить только volume `redis-data`.

---

### T19. Карточка плагина / кнопки в админке всё ещё показывают китайские иероглифы (支付方式 → Способ оплаты / 未安装 → Не установлено)

**Причина**: Upstream React-бандл `xboard-admin-dist` компилируется с жёстко-зашитыми zh-CN строками. Мы НЕ МОЖЕМ перекомпилировать React внутри контейнера.

**Исправление (уже встроено >=2026-09-12 в entrypoint: `patch_admin_cjk_to_ru`**: при каждом старте контейнера `xboard-web` запускается массовая `sed` на 90 замен CJK→RU над JS/CSS/HTML файлами `/www/public/assets/admin/**. Применяется автоматом. Ничего делать не надо, кроме рестарта:
```bash
cd /opt/xboard
docker compose restart xboard-web
sleep 30
# Проверка: в логах должна быть строка
docker compose logs --tail 5 xboard-web 2>&1 | grep "CJK patch done"
# Проверка: grep CJK→RU патч применился
docker exec xboard-web grep -a "Способ оплаты" /www/public/assets/admin/assets/*.js | head -1
```

Если вы всё ещё видите иероглифы после рестарта: принудительно пересоберите админку (сброс браузерного кеша, Ctrl+Shift+R):
```bash
# Полный сброс кеша админки + перезапуск патча
docker exec xboard-web sh -lc '
rm -rf /www/public/assets/admin
mkdir -p /www/public/assets
git clone --depth=1 https://github.com/cedar2025/xboard-admin-dist.git /www/public/assets/admin
rm -rf /www/public/assets/admin/.git /www/public/assets/admin/.github
'
docker compose restart xboard-web
```

---

### T20. Ключ выдан, но клиент «не подключается» (room.id = UUID вместо URL)

**Симптом.** В личном кабинете ключ выглядит как `olcrtc://jitsi?datachannel@<UUID>#<64 hex>`
(в поле комнаты голый UUID, например `@a1b2c3d4-...`), клиент OlcBox/owenclave не соединяется,
в логах olcrtc — ошибка парсинга комнаты / «room not found».

**Причина.** По конвенции olcrtc (`openlibrecommunity/olcrtc`, `docs/uri.md`) для провайдера
`jitsi` поле `room.id` ОБЯЗАНО быть **полным URL комнаты** (`https://<host>/<room>`), а не UUID.
Раньше `olcrtc-manager` генерировал `room.id = uuid.NewString()` → ключ был невалидным.
Исправлено в коммите `f1ed74c`.

**Правильный формат ключа:**
```
olcrtc://jitsi?datachannel@https://<JITSI-ХОСТ>/olcrtc-xxxxxxxx#<64 hex>$olc
```

**Диагностика (на VPS):**
```bash
cd /opt/xboard

# 1. Что реально лежит в комнатах инстансов (ожидаем https://... , а НЕ uuid)
docker exec xboard-olcrtc-manager sh -lc \
  'cat /var/lib/olcrtc-manager/instances/*.yaml 2>/dev/null | grep -i room' 

# 2. Какой Jitsi-хост видит менеджер (должен совпадать с .env OLCRMGR_JITSI_SERVER)
docker exec xboard-olcrtc-manager sh -lc 'env | grep OLCRMGR_JITSI_SERVER'

# 3. Доступен ли Jitsi-хост ИЗ контейнера (иначе клиент не подключится)
docker exec xboard-olcrtc-manager sh -lc \
  'wget -qS -O /dev/null https://meet.ffmuc.net 2>&1 | head -3'

# 4. Что отдаёт бэкенд Xboard (нужен токен пользователя)
curl -s "http://127.0.0.1:7001/api/olcrtc/diag?token=<USER_TOKEN>" | head -40
```

Ожидаем в п.1 строку `room: https://<host>/olcrtc-...` — если видите `room: <uuid>`, сработала старая логика.

**Фикс (2 шага).**

*Шаг 1 — задать Jitsi-хост (единый источник домена):*
```bash
cd /opt/xboard
# Пропишите/проверьте переменную в .env (или в compose-файле)
grep -q '^OLCRMGR_JITSI_SERVER=' .env || echo 'OLCRMGR_JITSI_SERVER=https://meet.ffmuc.net' >> .env

# Применить и пересоздать ТОЛЬКО менеджер (пересоздастся, чтобы подхватить ENV)
docker compose up -d --force-recreate olcrtc-manager
```
`olcrtc-manager` при старте вызывает `Manager.Migrate()` — он **сам** нормализует старые
UUID-комнаты в полный URL и перезаписывает YAML. Отдельных действий не требуется.

*Шаг 2 — перевыпустить ключ пользователя* (room уже мигрирован, но старый ключ в UI мог закешироваться):
- В ЛК нажмите **🔄 Пересоздать ключ (обновить)**, **или** через artisan:
```bash
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
> создаём вручную (как в `plugins/OlcRTC/Plugin.php::client()`).

**Проверка после фикса:** скопируйте свежий ключ из ЛК — поле комнаты должно быть
`https://<host>/olcrtc-...`, а `OLCRMGR_JITSI_SERVER` совпадать с реальным Jitsi-сервером.

**Симптом «IP restriction» (ключ перестал работать у всех сразу).** В логах инстансов
(`.../instances/<id>.log`) каждая попытка соединения завершается:
```
server: failed to connect link: session connect: jitsi join muc: xmpp dial: sasl: server error:
<stream:error ...><policy-violation .../><text ...>IP restriction</text></stream:error>
```
Смысл ошибки: **Jitsi-сервер забанил IP вашего VPS** и больше не пускает его в MUC-комнаты.
Менеджер при этом зацикливается на `instance <id> died, restarting` каждые 30 сек, туннели не
поднимаются, все ключи «мертвы».

> ⚠️ **Важно про `meet.egovm.ru`.** Это **вариант №1 по умолчанию** в официальном `install.sh`
> из гайда (`docs/jitsi.instances.yaml`, первая строка), и именно он даёт ключи, которые работают
> на Android в режиме «белых списков». **Но он же чаще всего и банит IP зарубежного VPS.**
> Проверено 2026-10-27: с нашего VDS `meet.egovm.ru` стабильно отдаёт `IP restriction`
> (`j=0 restr=8`), т.е. «утренний» ключ с этим хостом нерабочий — это НЕ ошибка генерации ключа.

**Главная причина бана — собственный флуд.** Хост банит IP, если клиент долбит его
бесконечными переподключениями. У нас источником флуда оказались **ручные podman-контейнеры
`install.sh`** (`olcrtc-server-*`, политика `restart=unless-stopped`), которые после смены
поведения хоста уходят в цикл падений «Up 1 second» → реконнект каждую секунду → вечный бан IP.
Их обязательно нужно остановить:
```bash
# найдите crash-loop'ы (Status = 'Up N seconds' у давно созданного контейнера)
podman ps -a --format '{{.Names}}|{{.Status}}|{{.Created}}'
# остановите все ручные серверы (unless-stopped → сами не поднимутся)
for c in $(podman ps -aq --filter 'name=olcrtc-server'); do podman stop "$c"; done
podman ps                                   # должно быть пусто
```
После остановки флуда подождите ~10 минут — бан обычно снимается автоматически.

**Проверка хостов «с нашей стороны» (годится или нет) — batch-пробой.** Создаём по инстансу на
каждый хост от лица тестового пользователя и смотрим логи:
```bash
cd /opt/xboard
KEY=$(sed -n 's/^OLCRMGR_API_KEY=//p' .env | head -n1 | tr -d '\r')
D=/var/lib/olcrtc-manager/instances; EXP=$(( $(date +%s) + 7200 )); i=400
for h in meet.egovm.ru meet.mamba.group meet.mirox.chat conf.expressmoney.com; do
  i=$((i+1))
  ID=$(docker compose exec -T xboard curl -s -X POST http://olcrtc-manager:8080/api/v1/instances \
    -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
    -d "{\"user_id\":9996,\"provider\":\"jitsi\",\"transport\":\"datachannel\",\"dns\":\"77.88.8.8:53\",\"expires_at\":$EXP,\"comment\":\"olc\",\"slot\":$i,\"host\":\"$h\"}" \
    | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
  echo "$h -> $ID"; echo "$h=$ID" >> /tmp/probe.map; sleep 1
done
sleep 60
for p in $(cat /tmp/probe.map); do
  h=${p%%=*}; id=${p#*=}
  docker compose exec -T olcrtc-manager sh -lc "f=$D/$id.log; echo \"$h j=\$(grep -c 'MUC joined' \$f) restr=\$(grep -c 'IP restriction' \$f)\""
done
```
Ожидаем `MUC joined` + `Link connected` (рабочий хост) либо `IP restriction` / `token required`.
**Проверено с нашего VDS 2026-10-27:** рабочие — `meet.mamba.group`, `meet.mirox.chat`,
`conf.expressmoney.com`, `meet.riddlerx.org`, `meet.ffmuc.net`, `meet.opensuse.org`;
забанен — `meet.egovm.ru`.

Одиночная быстрая проверка конкретного хоста:
```bash
cd /opt/xboard
printf 'mode: srv\nauth:\n    provider: jitsi\nroom:\n    id: %s/olcrtc-probe1\ncrypto:\n    key: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\nnet:\n    transport: datachannel\n    dns: 77.88.8.8:53\n' https://meet.mamba.group > /tmp/p.yaml
docker compose cp /tmp/p.yaml olcrtc-manager:/tmp/p.yaml
docker compose exec olcrtc-manager sh -lc 'timeout 20 /usr/local/bin/olcrtc /tmp/p.yaml 2>&1 | grep -E "MUC joined|restriction|error" | head -3'
```

**Где живёт выбор хоста.** Панель выдаёт пользователю **три ключа одновременно** — по одному на
Jitsi-хост; список и порядок заданы в `plugins/OlcRTC/Services/OlcRTCManagerClient::slots()`
и переопределяются ENV без правки кода:
```
OLCRTC_HOST_PRIMARY  → слот 0 (основной: ПК/ноутбук/Android)
OLCRTC_HOST_MOBILE   → слот 1 (обход белых списков мобильных операторов)
OLCRTC_HOST_ALT      → слот 2 (резерв)
```
> Текущее распределение хостов (2026-10-27): слот 0 = `meet.mamba.group`,
> слот 1 = `meet.riddlerx.org`, слот 2 = `conf.expressmoney.com`.
> `meet.mirox.chat` и `meet.egovm.ru` исключены (см. выше).
> ℹ️ `OLCRMGR_JITSI_SERVER` — это лишь **дефолтный** хост для инстансов, созданных БЕЗ параметра
> `host`. Панель всегда передаёт `host` явно по слотам, поэтому для мультиключа он почти не влияет.

Смена хоста для уже выданных ключей делается одним вызовом — `provisionAllSlots(recreate)`:
```bash
docker compose exec xboard php artisan tinker --execute='
$url = rtrim((string) admin_setting("olcrtc_manager_url","http://olcrtc-manager:8080"), "/");
$key = \Plugin\OlcRTC\Services\OlcRTCManagerClient::resolveApiKey($url, (string) admin_setting("olcrtc_manager_api_key",""));
$mgr = new \Plugin\OlcRTC\Services\OlcRTCManagerClient($url,$key,
    (string) admin_setting("olcrtc_default_provider","jitsi"),
    (string) admin_setting("olcrtc_default_transport","datachannel"),
    (string) admin_setting("olcrtc_default_dns","77.88.8.8:53"),
    (string) env("OLCRMGR_DEFAULT_TOKEN",""));
foreach (\App\Models\User::whereNotNull("plan_id")->get(["id","expired_at"]) as $u) {
    echo $u->id.": ".json_encode($mgr->provisionAllSlots((int)$u->id,(int)($u->expired_at??time()+31536000),true),JSON_UNESCAPED_SLASHES).PHP_EOL;
    usleep(900000); // обход rate-limit менеджера (10 rps)
}'
```
Ключи, привязанные к старому хосту в `room.id`, сами НЕ переключатся — их нужно перевыпустить.

**Нюансы:**
- Jitsi-хост для **выдаваемых ключей** задаётся слотами `OlcRTCManagerClient::slots()` и переопределяется ENV `OLCRTC_HOST_PRIMARY` / `OLCRTC_HOST_MOBILE` / `OLCRTC_HOST_ALT`; `OLCRMGR_JITSI_SERVER` в `.env` — лишь дефолт для инстансов без явного `host`. В админке/плагине такой настройки НЕТ (провайдер `jitsi` в плагине — лишь имя провайдера). После смены хоста перевыпустите ключи (см. выше).
- Метка `$olc` в конце ключа (`...#<key>$olc`) — часть спецификации URI v1 и **сохраняется** бэкендом; она не влияет на работу и делает ключ таким же по виду, как эталонный из гайда. Раньше (до коммита `d2f8d3f`) метка ошибочно вырезалась — если ключа нет `$olc`, обновите панель (см. README → команда обновления).
- Клиент должен поддерживать `datachannel` (OlcBox, owenclave). При выборе другого транспорта в админке ключ с `datachannel@` не совпадёт.

---

### T21. Сборка `olcrtc-manager` падает: `Remote branch latest not found` / curl 404

**Симптом.**
```
=> ERROR [olcrtc-manager olcrtc-fetcher 4/4] RUN ... releases/download/latest/olcrtc-linux-amd64.tar.gz
0.784 curl: (22) The requested URL returned error: 404
0.796 Download failed, building olcrtc from source...
1.256 fatal: Remote branch latest not found in upstream origin
target olcrtc-manager: failed to solve: ... exit code: 128
```
После этого `docker compose build` прерывает цепочку (`&& docker compose up -d` не выполняется),
контейнеры остаются на старом образе.

**Причина.** У репозитория `openlibrecommunity/olcrtc` **нет GitHub-релизов** вообще
(`releases` пустой) и **нет ветки `latest`** — существует только ветка `master`
и один тег `v0.0.1`. Поэтому:
- скачивание `releases/download/<X>/olcrtc-<arch>.tar.gz` даёт **404** при любом `<X>`;
- fallback `git clone --branch latest` падает, т.к. ветки `latest` не существует.

Ранее в compose-файлах (`compose*.yaml`, `.env.olcrtc.example`, GH-workflow) аргумент
`OLCRTC_RELEASE` по умолчанию был `latest` → сборка всегда падала.

**Фикс (уже в репозитории).** Дефолт `OLCRTC_RELEASE` заменён на `master` во всех compose-файлах,
`.env.olcrtc.example` и `.github/workflows/docker-publish.yml`. Скачивание релиза по-прежнему 404-нётся,
но автоматически срабатывает fallback: `git clone --branch master` + `go build ./cmd/olcrtc`.

**Что сделать на VDS:**
```bash
cd /opt/xboard && git fetch origin && git reset --hard origin/master

# На всякий случай зафиксируйте значение и в .env (перебивает дефолт compose):
grep -q '^OLCRTC_RELEASE=' .env || echo 'OLCRTC_RELEASE=master' >> .env
sed -i 's|^OLCRTC_RELEASE=latest|OLCRTC_RELEASE=master|' .env

docker compose build olcrtc-manager xboard
docker compose up -d
docker compose exec xboard php artisan optimize:clear
```

**Проверка:** в логе сборки увидите `Download failed, building olcrtc from source...`, затем
`Built olcrtc from source: ...` и образ соберётся успешно.

> ℹ️ Первая сборка идёт чуть дольше (компиляция Go). Если `go mod download` не проходит —
> проблема в доступе к `proxy.golang.org`, а не в этом баге.

---

### T22. Сайт/админка 500: `no such table: v2_plugins`

**Симптом.**
```
SQLSTATE[HY000]: General error: 1 no such table: v2_plugins
(Connection: sqlite, Database: /www/.docker/.data/xboard.sqlite,
 SQL: select * from "v2_plugins" where "is_enabled" = 1)
```
Открывается и админка (`/<hash>`), и главная (`/`) — обе отдают 500.

**Причина.** Файл SQLite есть, но миграция `2025_01_18_140511_create_plugins_table`
(и, возможно, другие) **не применена**. Типичные причины: установка/апдейт прервались
(нехватка диска, OOM), либо в `.env` стоит `INSTALLED=1` / уже есть таблица `users`,
из-за чего `entrypoint.sh` считает панель установленной и **пропускает** авто-установку,
а `xboard:update` на этом запуске не довёл миграции до конца.

**Диагностика (на VDS, из папки проекта).**
```bash
cd /opt/xboard

# 1. Что с файлом БД и местом на диске (частая первопричина — переполнение)
ls -la .docker/.data/
df -h /

# 2. Список реально существующих таблиц
docker compose exec xboard php -r '
$db = new PDO("sqlite:/www/.docker/.data/xboard.sqlite");
$t  = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\" ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo count($t), " таблиц(ы)\n", implode("\n", $t), "\n";'

# 3. Что говорил entrypoint/апдейт при последнем старте
docker compose logs --tail=150 xboard | grep -iE 'AUTO_INSTALL|migrat|v2_plugins|FATAL|error'
```

**Фикс (безопасно: `migrate` только ДОСОЗДАЁТ недостающие таблицы, данные не трогает).**
```bash
cd /opt/xboard

# 0. Если диск переполнен — сначала освободите место, иначе миграции снова упадут:
sudo truncate -s 0 storage/logs/*.log 2>/dev/null || true
df -h /

# 1. Догнать миграции (создаст v2_plugins и все прочие недостающие таблицы)
docker compose exec xboard php artisan migrate --force

# 2. Зарегистрировать/включить плагины (OlcRTC и core)
docker compose exec xboard php artisan tinker --execute='\App\Services\Plugin\PluginManager::installDefaultPlugins();'

# 3. Сбросить конфиг-кэш
docker compose exec xboard php artisan optimize:clear

# 4. Проверка
curl -s -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1:7001/
```
Ожидаем `HTTP 200` (или `302` на страницу логина), ошибка `no such table` исчезает.

**Нюансы.**
- **`install.sh` повторно не запускать** — он стирает БД и настройки.
- Если `migrate` падает с `database is locked` — остановите web на время миграции:
  `docker compose stop xboard && docker compose run --rm xboard php artisan migrate --force && docker compose start xboard`.
- Если БД действительно пустая (0 таблиц) — это чистая установка; выполните
  `docker compose run --rm --entrypoint "php artisan xboard:install --no-interaction" xboard`
  (с `AUTO_INSTALL=1` в compose это делает сам entrypoint при старте).

---

## T23. Ключ `olcrtc://…` выдан корректно, но **не подключается** (srv-процесс не стартует: флаг `-c`)

### Симптом
- В ЛК ключ имеет правильный вид: `olcrtc://jitsi?datachannel@https://<host>/olcrtc-xxxxxxxx#<64hex>$olc`.
- Клиент вставляет ключ, но туннель не поднимается — сервер (srv-нода) фактически не запущен.
- Контейнер `olcrtc-manager` при этом `Healthy`, в его логах ошибок нет.

### Root-cause
Есть **две** независимые причины, обе исправлены в коммите с T23.

**1. Флаг `-c` у бинаря olcrtc (нода не стартовала вовсе).**
`olcrtc` принимает **ровно один позиционный аргумент** — путь к YAML-конфигу, CLI-флагов нет
(см. upstream `cmd/olcrtc/main.go`: `if len(args) != 1 { return ErrConfigPathRequired }`).
`olcrtc-manager` запускал процесс как `olcrtc -c <cfg.yaml>` — второй аргумент `-c` приводил к
`usage: olcrtc <config.yaml>`, процесс завершался мгновенно, поэтому ключ никогда не работал.

**2. Нода перезапускалась при каждом обращении (туннель рвался).**
`Manager.Create` всегда гасил и поднимал srv-процесс, даже когда конфиг не менялся. А этот
эндпоинт дёргается при каждой загрузке ЛК (create-on-the-fly) и ежечасным кроном
`reconcileActiveUsers()` — то есть только что поднятый туннель тут же ронялся.
Теперь процесс перезапускается только при реальном изменении конфига (provider/transport/
token/dns/expires_at/room), а если конфиг тот же и процесс жив — ничего не трогается.
Кнопка «Пересоздать ключ» передаёт `recreate: true` и получает **новую комнату + новый ключ**.

### Метка `$olc` в ключе
Хвост `$<MIMO>` — по соглашению olcrtc (docs/uri) это **свободный клиентский комментарий**,
он не передаётся в `olcrtc` и ни на что не влияет, но выданный ключ должен иметь стабильный
вид `...$olc` как в гайде. Раньше служебные строки из внутренних вызовов (`reconcile`,
`trial 72h`, `auto-seed-YYYYMMDD`, `manual-recreate-…`) подставлялись в это поле и попадали
в ключ. Теперь все точки создания/обновления инстанса передают метку `olc`.
Уже существующие инстансы получат `$olc` при ближайшем часовом кроне (`reconcileActiveUsers`,
он обновляет то же поле) или сразу — по кнопке «Пересоздать ключ».

### Диагностика (на VDS, из папки проекта)
```bash
cd /opt/xboard

# 1. Что в логе инстанса (тут будет "usage: olcrtc <config.yaml>")
docker compose exec olcrtc-manager sh -c 'ls -l /var/lib/olcrtc-manager/instances/; tail -n 20 /var/lib/olcrtc-manager/instances/*.log'

# 2. Что за бинарь и как он принимает аргументы
docker compose exec olcrtc-manager /usr/local/bin/olcrtc --help
docker compose exec olcrtc-manager /usr/local/bin/olcrtc /nonexistent.yaml

# 3. Живые процессы srv
docker compose exec olcrtc-manager ps -ef | grep -v grep | grep olcrtc
```

### Правильный фикс
Исправлено в `olcrtc-manager/internal/manager/manager.go`:
запуск заменён на позиционный аргумент (`exec.Command(bin, cfgPath)`), плюс добавлены
`Restore()` (поднимает активные инстансы после рестарта контейнера) и однократный `Wait`
(иначе `reap()` перезапускал инстанс каждые 30 секунд). Обновите код и пересоберите образ:
```bash
cd /opt/xboard
git fetch origin && git reset --hard origin/master
docker compose build olcrtc-manager && docker compose up -d olcrtc-manager

# проверка: в логе инстанса нет "usage: olcrtc <config.yaml>", процесс жив
docker compose exec olcrtc-manager sh -c 'tail -n 20 /var/lib/olcrtc-manager/instances/*.log'
docker compose exec olcrtc-manager ps -ef | grep -v grep | grep olcrtc
```

### Если `docker compose build olcrtc-manager` падает: `no space left on device`
Сборка компилирует `olcrtc` из исходников (git clone + `go build`, сотни Go-модулей) —
на дисках ~15 ГБ это упирается в место. Освободите место:
```bash
cd /opt/xboard
docker builder prune -af
docker image prune -af
df -h /
```
Затем **переиспользуйте уже собранный бинарь olcrtc** из работающего контейнера — тогда
тяжёлая компиляция olcrtc полностью пропускается (сборка занимает ~1 минуту вместо ~15):
```bash
cd /opt/xboard
mkdir -p olcrtc-prebuilt
docker cp "$(docker compose ps -q olcrtc-manager)":/usr/local/bin/olcrtc olcrtc-prebuilt/olcrtc

docker compose build olcrtc-manager   # в логе будет "Using prebuilt olcrtc from build context"
docker compose up -d olcrtc-manager
```
`olcrtc-prebuilt/olcrtc` в git не коммитится (см. `.gitignore`), поэтому его нужно
складывать на VDS вручную (команда `docker cp` выше). Если файла нет — сборка просто
соберёт olcrtc из исходников, как раньше.

---

## T24. `client.yaml` и ссылка-подписка приходят битыми (`%!(EXTRA string=…)`)

### Симптом
Скачанный из ЛК файл `client.yaml` не парсится клиентом:
```
load config: parse config client.yaml: yaml: line 14: could not find expected ':'
```
или `curl` на `/api/v1/user/<id>/yaml` показывает тело:
```
application/yaml; charset=utf-8%!(EXTRA string=mode: cnc
auth:
    provider: jitsi
...
)
```
То же самое ломает `GET /api/v1/user/<id>/sub` («Открыть ссылку-подписку»).

### Root-cause
В `olcrtc-manager/internal/api/api.go` использовался `c.String`:
```go
c.String(http.StatusOK, "application/yaml; charset=utf-8", manager.BuildUserYAML(i))
```
У gin сигнатура `c.String(code int, format string, values ...any)`: **второй аргумент — это
`fmt`-формат**, а не Content-Type. Так как в нём нет глаголов (`%s`), gin дописывает
`%!(EXTRA string=<тело>)`, а тело уходит как аргумент формата. Клиент получает мусор.

### Диагностика (1 команда)
```bash
cd /opt/xboard
docker compose exec xboard sh -c 'curl -sS -H "Authorization: Bearer $OLCRMGR_API_KEY" \
  http://olcrtc-manager:8080/api/v1/user/1/yaml' | head -3
```
Если в выводе есть `%!(EXTRA string=` — баг присутствует.

### Фикс
`c.String(code, contentType, body)` → `c.Data(code, contentType, []byte(body))`
(также исправлен `getUserSub` и ветка «no active subscription»). После правки:
```bash
cd /opt/xboard
git fetch origin && git reset --hard origin/master
docker compose build olcrtc-manager
docker compose up -d olcrtc-manager
```
Проверка — тело должно начинаться сразу с `mode: cnc`:
```bash
docker compose exec xboard sh -c 'curl -sS -H "Authorization: Bearer $OLCRMGR_API_KEY" \
  http://olcrtc-manager:8080/api/v1/user/1/yaml' | head -3
```

### Важно
Пока баг не исправлен, работоспособен **только** URI-ключ (`olcrtc://…`), а
«⬇️ Скачать client.yaml» и «🔗 Скопировать ссылку-подписку» отдают невалидный контент.

---

## T25. После рестарта/пересборки кабинет пуст: нет ключа и ссылки-подписки, `/api/olcrtc/*` → 401

### Симптом
- ЛК открывается и авторизуется, но в разделе подписки **нет ключа и ссылки-подписки**,
  либо `/api/olcrtc/*` отвечает `401 Unauthorized` (в т.ч. прямая проверка
  `curl -H "Authorization: Bearer $OLCRMGR_API_KEY" http://olcrtc-manager:8080/api/v1/user/1/sub`).
- Кнопки «Скачать client.yaml» и «Скопировать ссылку-подписку» не работают.
- Появилось **после** `docker compose up -d` / `docker compose restart`, хотя до этого всё работало.

### Root-cause
`xboard:install` (авто-установка из `entrypoint.sh`) **безусловно копировал `.env.example` → `.env`**,
затирая интеграционные переменные, которые прописал `install.sh`:
`OLCRMGR_API_KEY`, `OLCRMGR_URL`, `XBOARD_PORT`, `ADMIN_ACCOUNT/ADMIN_PASSWORD`,
`YOOMONEY_*`, `AUTO_SEED`, `ADMIN_SPA_OLCRTC_ONLY_PATCH` и т.п.

Контейнер `xboard` держит своё окружение с момента создания, но `olcrtc-manager` при следующем
пересоздании читает уже «затёртый» `.env` и получает **дефолтный ключ из compose**
(`OLCRMGR_API_KEY=please-change-me-in-env-file`). Ключ в менеджере перестаёт совпадать с тем,
что сохранён в настройках плагина OlcRTC, — и любой запрос к менеджеру отклоняется по 401.

### Диагностика (на VDS, из папки проекта)
```bash
cd /opt/xboard

# 1. Остались ли интеграционные переменные в .env? (пусто → переменные затёрты)
grep -E 'OLCRMGR_API_KEY|XBOARD_PORT|ADMIN_ACCOUNT|YOOMONEY_WALLET|AUTO_SEED' .env

# 2. Ключи в обоих контейнерах — ДОЛЖНЫ совпадать
docker compose exec xboard        sh -c 'echo web=$OLCRMGR_API_KEY'
docker compose exec olcrtc-manager sh -c 'echo mgr=$OLCRMGR_API_KEY'

# 3. Ответ менеджера с ключом из web (401 → ключи разошлись)
docker compose exec xboard sh -c 'curl -sS -o /dev/null -w "mgr=%{http_code}\n" \
  -H "Authorization: Bearer $OLCRMGR_API_KEY" http://olcrtc-manager:8080/api/v1/user/1/sub'
```
Если `mgr=please-change-me-in-env-file` (или ключи не совпадают) — это T25.

### Фикс руками (если не хотите обновлять код)
```bash
cd /opt/xboard

# 1. Верните переменные в .env. Ключ должен совпадать с настройками плагина OlcRTC в админке.
grep -q '^OLCRMGR_API_KEY=' .env || echo 'OLCRMGR_API_KEY=<ваш-ключ>' >> .env

# 2. Пересоздайте оба контейнера, чтобы они подхватили ENV
docker compose up -d olcrtc-manager xboard

# 3. Сбросьте кэш конфига
docker compose exec xboard php artisan optimize:clear
```

### Правильный фикс
Исправлено в `app/Console/Commands/XboardInstall.php` (коммит `067a972`): `.env` создаётся из
`.env.example` **только если файла нет**, иначе лишь **дописываются отсутствующие ключи**,
а всё существующее сохраняется.
```bash
cd /opt/xboard
git fetch origin && git reset --hard origin/master
docker compose build xboard && docker compose up -d xboard
```

### Важно
- Значение `OLCRMGR_API_KEY` в `.env` и в настройках плагина OlcRTC (админка) **должны
  совпадать**, иначе менеджер отвечает 401.
- Фикс предотвращает будущие потери, но **не восстанавливает** уже затёртый `.env` —
  шаг «Фикс руками» всё равно нужен один раз.
- `install.sh` повторно **не запускать** — он стирает БД и настройки.

---

## T26. Обновили ядро `openlibrecommunity/olcrtc`, а версия не изменилась (Docker build-cache)

### Симптом
В апстриме `openlibrecommunity/olcrtc` появились новые коммиты, вы выполнили
`git reset --hard origin/master && docker compose build olcrtc-manager && docker compose up -d`,
но `docker compose exec olcrtc-manager cat /etc/olcrtc.version` возвращает **тот же** SHA, что и раньше —
новый код ядра (то, что реально выдаёт ключи `olcrtc://…`) не приехал.

### Root-cause
Бинарник `olcrtc` собирается **внутрь** образа `olcrtc-manager` (stage `olcrtc-fetcher`
в `olcrtc-manager.Dockerfile`) командой `git clone --branch master … && go build`. Docker кэширует
слои сборки, и пока входные данные RUN-слоя не менялись, он отдаёт **старый закэшированный** слой —
новый HEAD апстрима не подтягивается. `git reset --hard` в проекте Xboard тут не помогает: это
**другой** репозиторий, он клонируется во время сборки образа.

### Диагностика
```bash
cd /opt/xboard
docker compose exec olcrtc-manager cat /etc/olcrtc.version        # установленный SHA
curl -s http://127.0.0.1:8080/healthz                             # {"status":"ok","olcrtc_version":"<sha>"}
# последний коммит апстрима:
curl -fsSL -H 'Accept: application/vnd.github+json' \
  'https://api.github.com/repos/openlibrecommunity/olcrtc/commits?sha=master&per_page=1' | grep -m1 '"sha"'
```
SHA совпали → ядро уже актуально. Разные → нужен cache-busting build-arg.

### Фикс руками
```bash
cd /opt/xboard
SHA=$(curl -fsSL -H 'Accept: application/vnd.github+json' \
  'https://api.github.com/repos/openlibrecommunity/olcrtc/commits?sha=master&per_page=1' \
  | grep -o '"sha": *"[0-9a-f]\{40\}"' | head -n1 | grep -o '[0-9a-f]\{40\}')
docker compose build --build-arg "OLCRTC_COMMIT=$SHA" olcrtc-manager
docker compose up -d --force-recreate olcrtc-manager
```

### Правильный фикс
Добавлен build-arg `OLCRTC_COMMIT` (входит в cache-key → инвалидирует слой), версия ядра
пишется в `/etc/olcrtc.version` и отдаётся в `/healthz`, а скрипт `update-olcrtc.sh` делает всё
в одну команду (+ cron-автообновление). См. **QUICKSTART.md → §2.1**.
```bash
cd /opt/xboard
sh update-olcrtc.sh                  # обновить ядро (одна команда)
sh update-olcrtc.sh --install-cron   # поставить автообновление раз в неделю
```

### Важно
- Это **другой** репозиторий, не `vsvavan2/Xboard`. `git reset --hard origin/master` в проекте
  Xboard ядро **не** обновляет.
- Ключи клиентов (`olcrtc://…`) при обновлении ядра **не меняются** — переустанавливать клиентам
  ничего не нужно.
- `sh update.sh` теперь **сам** подтягивает свежее ядро (узнаёт SHA апстрима и прокидывает в сборку).

---

## T27. ЮMoney: при оплате редирект на `quickpay/error?reason=referer` («На этом сайте переводы недоступны»)

### Симптом
Пользователь нажимает «Оформить заказ» → браузер уводит не на форму оплаты, а на
`https://yoomoney.ru/transfer/quickpay/error?reason=referer`
со текстом «На этом сайте переводы недоступны. Если вы — владелец формы (кнопки),
напишите службе поддержки ЮMoney. Укажите полный адрес сайта, на котором стоит форма».

### Root-cause
ЮMoney «привязывает» быстрое платёжное окно (Quickpay) к сайту по HTTP-заголовку **Referer**.
Если домен панели не зарегистрирован в кошельке как «магазин/сайт», запрос с непустым Referer
отклоняется с `reason=referer`. Ссылка на оплату работает, если открыть её напрямую
(без Referer) — поэтому проблема проявляется только при переходе **со страниц панели**.

### Фикс (уже в сборке)
От страниц панели Referer вообще не отправляется — этого достаточно, чтобы ЮMoney принял платёж:
- `<meta name="referrer" content="no-referrer">` в шаблоне ЛК (`theme/Xboard/dashboard.blade.php`) и в `index.html`;
- ответный заголовок `Referrer-Policy: no-referrer` на уровне Caddy (`.docker/caddy/Caddyfile`);
- в собственном обработчике оплаты виджета переход делается по ссылке с `rel="noreferrer"`
  (`payGoNoRef()` в `dashboard.blade.php`).

Примените на VDS:
```bash
cd /opt/xboard
git fetch --all && git reset --hard origin/master
sh update.sh      # пересоберёт образ (Caddy-заголовок) и очистит кэш шаблонов
```
> `theme/` смонтирован снаружи (`:ro`), поэтому правки Blade подхватываются после
> `php artisan optimize:clear`; а вот заголовок Caddy внутри образа — только после
> `docker compose build xboard`.

### Если ошибка осталась
Откройте ссылку оплаты отдельно (скопировать URL в новую вкладку) — если так платёж
проходит, значит где-то всё ещё протекает Referer (проверьте промежуточный прокси/CDN,
который может добавлять заголовок). Альтернатива — добавить домен в «Мои сайты» кошелька ЮMoney.

---

## T28. В админке/ЛК цены в юанях (`¥` / `元`, валюта `CNY`) вместо рублей

### Симптом
В админке (например, «Фиксированная комиссия → 元») и в личном кабинете валюта отображается
как `¥`/`元`, а не `₽`.

### Root-cause
В апстриме Xboard дефолт — `currency=CNY`, `currency_symbol=¥`, и это значение уже записано
в `v2_settings` на старых установках. Просто поменять дефолты в коде недостаточно: контроллеры
читали сохранённое «юаневое» значение из БД.

### Фикс (уже в сборке)
- В `app/Helpers/Functions.php` добавлены помощники `xboard_currency()` / `xboard_currency_symbol()`,
  которые приводят «юаневые» значения (`CNY`/`¥`/`元`/пусто) к `RUB` / `₽`.
- Контроллеры `V1\User\CommController`, `V2\Admin\ConfigController`, `V2\Client\AppController`
  используют эти помощники → рубль отображается и в админке, и в ЛК.
- При установке (`XboardInstall`) в `v2_settings` пишутся `currency=RUB`, `currency_symbol=₽`.

Примените на VDS так же, как в T27 (`git reset --hard origin/master && sh update.sh`).

---

## T29. Нет HTTPS (:443) → ЮKassa не доставляет вебхуки («уведомления не приходят»)

### Симптом
Пользователь платит, деньги списываются, но **статус заказа в админке не меняется**.
В ЛК ЮKassa событие висит с ошибкой доставки, либо при регистрации уведомления она отвечает
`Only HTTPS is allowed` / «Адрес должен быть HTTPS на порту 443 (8443)».
Локально панель доступна как `http://<IP>:7001/…`.

### Root-cause
По умолчанию `compose.yaml` публикует только `:80` (и `:7001`), без TLS. ЮKassa шлёт
HTTP-уведомления **только по HTTPS на 443/8443** и `http://IP:7001/…` отклоняет.
(ЮMoney, в отличие от неё, принимает и HTTP — но для возврата пользователя на сайт домен тоже нужен.)

### Фикс — встроенный TLS-терминатор (профиль `tls`, Let's Encrypt)
Домен должен уже указывать A-записью на этот сервер. Внешний nginx/Caddy при этом не нужен —
в проекте есть сервис `caddy-tls` (`.docker/caddy/Caddyfile.tls`), который слушает `:443`,
выпускает сертификат Let's Encrypt по **TLS-ALPN-01** и проксирует всё на `xboard:7001`.

```bash
cd /opt/xboard
# 1) включаем профиль "tls" и свой домен
sed -i '/^COMPOSE_PROFILES=/d;/^APP_DOMAIN=/d' .env
printf '\nCOMPOSE_PROFILES=tls\nAPP_DOMAIN=vless.24x7.hk\n' >> .env
# 2) APP_URL тоже на https (иначе Webhook URL в админке покажется как http)
sed -i 's|^APP_URL=.*|APP_URL=https://vless.24x7.hk|' .env
# 3) поднимаем TLS-контейнер (слушает :443), первый запуск = выпуск сертификата
docker compose up -d caddy-tls
# 4) применяем новый APP_URL
docker compose exec xboard php artisan optimize:clear
```

> ℹ️ `:80` занят основным контейнером, поэтому в `Caddyfile.tls` HTTP-челлендж отключён и
> используется **TLS-ALPN-01** (валидация по 443). Дополнительно открывать 80 для ACME не нужно.

### Проверка (4 команды)
```bash
cd /opt/xboard
curl -sSI https://vless.24x7.hk/ | head -1                                   # ожидаем HTTP/2 200
echo | openssl s_client -connect vless.24x7.hk:443 -servername vless.24x7.hk 2>/dev/null \
  | openssl x509 -noout -subject -dates                                     # CN = ваш домен, notAfter …
docker compose logs --tail=20 caddy-tls                                     # ошибок ACME нет
docker compose exec xboard php artisan tinker --execute='echo config("app.url");'   # https://…
```
В админке → **Платежи → ЮKassa** поле **Webhook URL** должно стать
`https://vless.24x7.hk/api/v1/guest/payment/notify/yookassa/<UUID>` — скопируйте его
в ЛК ЮKassa → **Интеграция → HTTP-уведомления**.

### Частые ошибки ACME
| Ошибка в логе `caddy-tls` | Причина / решение |
|---|---|
| `invalidContact` / `contact email is invalid` | В `Caddyfile.tls` задан запрещённый контакт (напр. `admin@example.com`). Уберите строку `email …` — Let's Encrypt выпускает сертификат и без контакта. |
| `no such host` / `DNS problem: NXDOMAIN` | A-запись домена ещё не разошлась или указывает не на этот IP: `dig +short vless.24x7.hk`. |
| `timeout during challenge` / порт закрыт | :443 недоступен снаружи — проверьте firewall/security-group: `ufw allow 443/tcp`. |
| `too many requests` (rate limit LE) | Подождите ~1 час или выпустите на staging-окружении, пока отлаживаете DNS. |

### Откат (вернуться на HTTP)
```bash
cd /opt/xboard
sed -i '/^COMPOSE_PROFILES=tls/d' .env
sed -i 's|^APP_URL=.*|APP_URL=http://<IP>:7001|' .env
docker compose stop caddy-tls && docker compose rm -f caddy-tls
docker compose exec xboard php artisan optimize:clear
```
Сертификат при этом остаётся в volume `caddy-data` — при повторном `up -d caddy-tls`
он переиспользуется, повторной валидации ACME не будет. Продление автоматическое.

> ℹ️ При `git reset --hard origin/master && sh update.sh` (`update.sh` сам увидит в `.env`
> `COMPOSE_PROFILES=…tls` и выполнит `docker compose up -d caddy-tls` + плавный
> `caddy reload`, не пересоздавая контейнер и не теряя сертификат).

---

## T30. Ключ «откатывается» на старый Jitsi-хост (ключ меняется сам, слот 2 снова `meet.ffmuc.net`)

### Симптом
Панель выдаёт не тот ключ, который прописан в коде: например, `slots()` в контейнере перечисляет
`meet.mamba.group / meet.mirox.chat / meet.riddlerx.org`, а в ЛК и в `olcrtc-manager` слот 2
снова становится `meet.ffmuc.net`. Слаг/комната ключа меняется сама по себе, `updated_at`
инстанса растёт каждые ~20 секунд, у пользователя «ключ перестал подключаться».

### Root-cause
ЛК опрашивает `GET /api/olcrtc/widget` каждые ~20 секунд, а этот маршрут **безусловно**
вызывает `OlcRTCManagerClient::provisionAllSlots()` — то есть на каждый опрос шлёт в
`olcrtc-manager` по одному `POST /api/v1/instances` на каждый слот с `host` из `slots()`.

Панель работает под **Laravel Octane**, а `opcache.validate_timestamps=Off`
(`opcache.revalidate_freq=0`): воркеры держат PHP-код плагина **в памяти** и НЕ подхватывают
изменённый файл с диска. Если файл плагина перезаписали «на горячую» (ручной `docker cp`,
`scp` в `/www/plugins`, правка в работающем контейнере), в памяти остаётся СТАРАЯ версия
`slots()`. Тогда виджет каждые 20 секунд «навязывает» менеджеру старый хост → инстанс
пересоздаётся (`re-home`) и ключ откатывается.

Обычный деплой (`update.sh`) этой проблемы не даёт: он делает
`docker compose up -d --force-recreate xboard` и тем самым перезагружает воркеры.

### Диагностика (3 команды)
```bash
cd /opt/xboard
# 1) что лежит НА ДИСКЕ в контейнере (эталон)
docker compose exec -T xboard grep -n "host'  *=>" /www/plugins/OlcRTC/Services/OlcRTCManagerClient.php
# 2) что реально уходит в менеджер (смотрите поле host в телах POST)
docker compose exec -T xboard sh -lc 'curl -s -H "Authorization: Bearer $OLCRMGR_API_KEY" http://olcrtc-manager:8080/api/v1/user/1'
# 3) состояние воркеров Octane и режим opcache
docker compose exec -T xboard php artisan octane:status
docker compose exec -T xboard php -i | grep -E 'opcache.validate_timestamps|opcache.enable'
```

### Фикс
```bash
cd /opt/xboard
# мягкая перезагрузка воркеров (код перечитывается с диска, соединения не рвутся)
docker compose exec -T xboard php artisan octane:reload
# если не помогло — полный рестарт контейнера
docker compose restart xboard
```
После перезагрузки `updated_at` инстанса перестаёт расти каждые 20 с, `pid` слота не меняется,
`host` совпадает с `slots()` на диске.

### Профилактика
- После ЛЮБОЙ правки PHP-файлов плагина/маршрутов в работающем контейнере сразу выполняйте
  `php artisan octane:reload` (и `php artisan optimize:clear` — для Blade/маршрутов).
- Не редактируйте `/www/plugins/**` «на горячую»: штатный путь — коммит в git + `sh update.sh`
  (или авто-деплой по cron), который сам пересоздаёт контейнер.
- Помните, что `/api/olcrtc/widget` провижинит все слоты на **каждый** опрос — это осознанно
  (чтобы существующие пользователи получали новые ключи автоматически), но усиливает эффект
  устаревшего кода в памяти Octane.

---

## 🧹 Полная чистая переустановка (последнее средство — 5 команд)

Если всё сломалось и вы хотите вернуться в состояние "с нуля":

```bash
cd /opt/xboard
# 1. Убиваем контейнеры + УДАЛЯЕМ VOLUMES (redis + xboard sqlite + olcrmgr-data)
docker compose down -v

# 2. Чистим .env (INSTALLED=true и т.д.)
rm -f .env && touch .env

# 3. Принудительно пересобираем оба образа ЛОКАЛЬНО, игнорируя GHCR denied
docker compose build xboard --no-cache
docker compose build olcrtc-manager --no-cache

# 4. One-click installer (актуальная версия с GitHub)
curl -fsSL https://raw.githubusercontent.com/vsvavan2/Xboard/master/install.sh | bash

# 5. Health check через 60 секунд (Octane+RoadRunner+Caddy прогреваются)
sleep 60
cd /opt/xboard
SEC=$(sqlite3 ./.docker/.data/xboard.sqlite "SELECT value FROM v2_system_config WHERE name='secure_path' LIMIT 1;")
IP=$(curl -s --max-time 5 https://ifconfig.me)
echo "=== ГОТОВО ==="
echo "Админка: http://${IP}:7001/${SEC}"
echo "Логин  : admin@example.com / Admin123456"
curl -sS -o /dev/null -w "HTTP/7001: %{http_code}\n" --max-time 10 http://127.0.0.1:7001/
```

Готово — это 100% триггер регрессии к состоянию "commit 0bc7a90, установка 49fd0db + 0bc7a90".
