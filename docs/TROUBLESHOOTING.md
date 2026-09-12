# TROUBLESHOOTING.md — Диагностика и исправления (vsvavan2/Xboard)

> Все баги ниже **уже исправлены** в коммитах `175fdfe` → `e40731a` → `c030eed` → `49fd0db` → `0bc7a90`.
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

Отправьте вывод в GitHub Issue / чат поддержки — **все 18 багов детерминируются по этому выводу**.

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
