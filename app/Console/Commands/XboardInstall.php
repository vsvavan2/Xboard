<?php

namespace App\Console\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Models\Plugin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\Knowledge;
use App\Models\Setting;
use App\Utils\Helper;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

class XboardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xboard:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'xboard 初始化安装';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $isDocker = file_exists('/.dockerenv');
            $autoInstall = (bool) getenv('AUTO_INSTALL', false);
            $enableSqlite = (bool) getenv('ENABLE_SQLITE', false);
            $enableRedis = (bool) getenv('ENABLE_REDIS', false);
            $dbTypeEnv = getenv('DB_TYPE', false);
            $adminAccount = getenv('ADMIN_ACCOUNT', false);
            $adminPassword = getenv('ADMIN_PASSWORD', false);
            $redisHostEnv = getenv('REDIS_HOST', false);
            $redisPortEnv = getenv('REDIS_PORT', false);
            $redisPasswordEnv = getenv('REDIS_PASSWORD', false);
            $dbWipe = (bool) getenv('DB_FORCE_WIPE', false);
            $this->info("__    __ ____                      _  ");
            $this->info("\ \  / /| __ )  ___   __ _ _ __ __| | ");
            $this->info(" \ \/ / | __ \ / _ \ / _` | '__/ _` | ");
            $this->info(" / /\ \ | |_) | (_) | (_| | | | (_| | ");
            $this->info("/_/  \_\|____/ \___/ \__,_|_|  \__,_| ");
            $alreadyInstalled =
                (File::exists(base_path() . '/.env') && $this->getEnvValue('INSTALLED'))
                || (getenv('INSTALLED', false) && $isDocker);

            if ($alreadyInstalled) {
                $dbConn = Config::get('database.default', 'sqlite');
                $dbRealFile = null;
                $dbExists = true;
                if ($dbConn === 'sqlite') {
                    $dbCfg = Config::get('database.connections.sqlite.database', base_path('.docker/.data/xboard.sqlite'));
                    $dbRealFile = (is_string($dbCfg) && str_starts_with($dbCfg, '/'))
                        ? $dbCfg
                        : base_path(ltrim($dbCfg ?? '.docker/.data/xboard.sqlite', '/\\'));
                    if (!File::exists($dbRealFile) || @filesize($dbRealFile) < 1) {
                        $dbExists = false;
                        @mkdir(dirname($dbRealFile), 0775, true);
                        @touch($dbRealFile);
                        @chmod($dbRealFile, 0664);
                        @chown($dbRealFile, 'www-data');
                        @chgrp($dbRealFile, 'www-data');
                        $this->warn("⚠️  INSTALLED=1 flag present, but SQLite DB file missing/empty ({$dbRealFile}). Re-running fresh install.");
                    } else {
                        try {
                            DB::purge('sqlite');
                            Config::set('database.connections.sqlite.database', $dbRealFile);
                            DB::connection('sqlite')->getPdo();
                            $tables = DB::connection('sqlite')->getPdo()
                                ->query("SELECT name FROM sqlite_master WHERE type='table'")
                                ->fetchAll(\PDO::FETCH_COLUMN);
                            if (count($tables) < 5) {
                                $dbExists = false;
                                $this->warn('⚠️  INSTALLED=1 flag present, but SQLite DB has no tables. Re-running fresh install.');
                            }
                        } catch (\Throwable $e) {
                            $dbExists = false;
                            $this->warn('⚠️  INSTALLED=1 flag present, but SQLite DB is unreadable (' . $e->getMessage() . '). Re-running fresh install.');
                        }
                    }
                }
                if (!$dbExists) {
                    $alreadyInstalled = false;
                }
            }

            if ($alreadyInstalled) {
                $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("✅ Панель уже установлена. URL админки: http(s)://ваш-сайт/{$securePath} — не забудьте сменить пароль в разделе «Мой профиль».");
                $this->warn('Чтобы ПЕРЕУСТАНОВИТЬ панель с нуля — ОЧИСТИТЕ содержимое файла .env в корне проекта (НО НЕ УДАЛЯЙТЕ сам .env при Docker-развёртывании).');
                $this->warn('Быстрая команда очистки .env:');
                note('rm .env && touch .env');

                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('v2_plugins')) {
                        $this->info('Проверка плагинов по умолчанию (idempotent)...');
                        try {
                            Artisan::call('migrate', ['--force' => true]);
                            PluginManager::installDefaultPlugins();
                            $this->info('Плагины по умолчанию синхронизированы ✓');
                        } catch (\Throwable $e) {
                            $this->warn('Не удалось синхронизировать плагины: ' . $e->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn('⚠️  INSTALLED=1 but DB probe failed: ' . $e->getMessage() . '. Re-running fresh install.');
                    $alreadyInstalled = false;
                }
            }
            if ($alreadyInstalled) {
                return;
            }
            if (is_dir(base_path() . '/.env')) {
                $this->error('😔 Ошибка установки: в Docker-окружении файл .env должен быть обычным текстовым файлом (НЕ папкой). Проверьте права и создайте пустой файл .env в корне.');
                return;
            }
            // 🔽 Выбор типа БД (в интерактивном режиме спрашиваем у пользователя)
            if ($autoInstall) {
                if ($dbTypeEnv && in_array($dbTypeEnv, ['sqlite', 'mysql', 'postgresql'], true)) {
                    $dbType = $dbTypeEnv;
                } elseif ($enableSqlite || !$dbTypeEnv) {
                    $dbType = 'sqlite';
                } else {
                    $dbType = $dbTypeEnv;
                }
            } else {
                $dbType = $enableSqlite ? 'sqlite' : select(
                    label: 'Выберите тип базы данных',
                    options: [
                        'sqlite' => '✅ SQLite (рекомендуется — ничего дополнительно ставить не нужно, файл .db в проекте)',
                        'mysql' => 'MySQL 5.7+ / MariaDB 10.x (нужен отдельный сервер MySQL)',
                        'postgresql' => 'PostgreSQL 13+ (нужен отдельный сервер PostgreSQL)'
                    ],
                    default: 'sqlite'
                );
            }

            // match → сконфигурировать env БД по выбранному типу
            $envConfig = match ($dbType) {
                'sqlite' => $this->configureSqlite(),
                'mysql' => $this->configureMysql(),
                'postgresql' => $this->configurePostgresql(),
                default => throw new \InvalidArgumentException("Выбран неподдерживаемый тип БД: {$dbType}")
            };

            if (is_null($envConfig)) {
                return; // Пользователь сам отменил установку в диалоге (выбрал «не очищать БД»)
            }
            $envConfig['APP_KEY'] = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));
            $isReidsValid = false;
            while (!$isReidsValid) {
                // ----------------------------------------------------------------
                // Headless (AUTO_INSTALL) path — use ENV-provided Redis first.
                // Redis config resolution order:
                //   1. REDIS_HOST env set AND is a path (starts with "/") → embedded socket
                //   2. REDIS_HOST env set AND NOT a path               → external TCP hostname
                //   3. ENABLE_REDIS=true + Docker                       → default host "redis" (compose service)
                //   4. Interactive prompts.
                // ----------------------------------------------------------------
                if ($autoInstall) {
                    $redisHost = (string) $redisHostEnv;
                    $redisPort = $redisPortEnv !== false ? (string) $redisPortEnv : '6379';
                    $redisPass = $redisPasswordEnv !== false ? (string) $redisPasswordEnv : '';

                    if ($redisHost === '' || $redisHost === false) {
                        if ($enableRedis && $isDocker) {
                            $redisHost = 'redis';
                            $redisPort = '6379';
                        } else {
                            $redisHost = '127.0.0.1';
                            $redisPort = '6379';
                        }
                    }

                    if (str_starts_with($redisHost, '/')) {
                        // Unix socket — embedded redis inside container (/data/redis.sock)
                        $envConfig['REDIS_HOST'] = $redisHost;
                        $envConfig['REDIS_PORT'] = 0;
                        $envConfig['REDIS_PASSWORD'] = null;
                        $isReidsValid = true;
                    } else {
                        // TCP hostname — external compose service, remote host, etc.
                        $envConfig['REDIS_HOST'] = $redisHost;
                        $envConfig['REDIS_PORT'] = $redisPort;
                        $envConfig['REDIS_PASSWORD'] = $redisPass;
                        $redisConfig = [
                            'client' => 'phpredis',
                            'default' => [
                                'host' => $envConfig['REDIS_HOST'],
                                'password' => $envConfig['REDIS_PASSWORD'],
                                'port' => (int) $envConfig['REDIS_PORT'],
                                'database' => 0,
                            ],
                        ];
                        try {
                            $redis = new \Illuminate\Redis\RedisManager(app(), 'phpredis', $redisConfig);
                            $redis->ping();
                            $isReidsValid = true;
                        } catch (\Exception $e) {
                            $this->warn("Redis TCP {$redisHost}:{$redisPort} недоступен ({$e->getMessage()}) — записываем как есть, попробуем later.");
                            $isReidsValid = true;
                        }
                    }
                    break;
                }

                // Docker-env: спрашиваем использовать ли встроенный Redis compose service
                $useBuiltinRedis = $isDocker && ($enableRedis || confirm(label: 'Использовать встроенный Redis из docker compose? (рекомендуется)', default: true, yes: '✅ Да, использовать контейнер redis:', no: '❌ Нет, у меня свой внешний Redis'));
                if ($useBuiltinRedis) {
                    if ($redisHostEnv && is_string($redisHostEnv) && !str_starts_with($redisHostEnv, '/')) {
                        // Пользователь передал REDIS_HOST через env — приоритетнее unix-сокета
                        $envConfig['REDIS_HOST'] = $redisHostEnv;
                        $envConfig['REDIS_PORT'] = $redisPortEnv !== false ? (int) $redisPortEnv : 6379;
                        $envConfig['REDIS_PASSWORD'] = $redisPasswordEnv !== false ? (string) $redisPasswordEnv : '';
                    } else {
                        // Вариант A: Docker-internal unix socket (самый быстрый, без портов)
                        $envConfig['REDIS_HOST'] = '/data/redis.sock';
                        $envConfig['REDIS_PORT'] = 0;
                        $envConfig['REDIS_PASSWORD'] = null;
                    }
                    $isReidsValid = true;
                    break;
                }
                $defaultRedisHost = $isDocker && $enableRedis ? 'redis' : '127.0.0.1';
                $envConfig['REDIS_HOST'] = text(label: 'Введите HOST (адрес) Redis', default: $defaultRedisHost, required: true, description: 'для compose = redis; для внешнего = IP/домен; для сокета /путь.sock');
                $envConfig['REDIS_PORT'] = text(label: 'Введите PORT Redis', default: '6379', required: true, description: 'для TCP=6379; для unix-socket=0');
                $envConfig['REDIS_PASSWORD'] = text(label: 'Введите пароль Redis (если нет — оставьте пустым)', default: '', required: false);
                $redisConfig = [
                    'client' => 'phpredis',
                    'default' => [
                        'host' => $envConfig['REDIS_HOST'],
                        'password' => $envConfig['REDIS_PASSWORD'],
                        'port' => (int) $envConfig['REDIS_PORT'],
                        'database' => 0,
                    ],
                ];
                try {
                    $redis = new \Illuminate\Redis\RedisManager(app(), 'phpredis', $redisConfig);
                    $redis->ping();
                    $isReidsValid = true;
                } catch (\Exception $e) {
                    // Пинговать не удалось — пишем ошибку, разрешаем повторить
                    $this->error("❌ Не удалось подключиться к Redis {$envConfig['REDIS_HOST']}:{$envConfig['REDIS_PORT']}. Ошибка: " . $e->getMessage());
                    $this->info('↻ Повторите ввод параметров Redis или нажмите Ctrl+C для выхода.');
                    $enableRedis = false;
                    sleep(1);
                }
            }

            if (!copy(base_path() . '/.env.example', base_path() . '/.env')) {
                abort(500, 'Не удалось скопировать .env.example → .env. Проверьте права на запись в корневую папку проекта (chown/chmod).');
            }
            ;
            if ($autoInstall) {
                $email = !empty($adminAccount) ? $adminAccount : 'admin@example.com';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("⚠️  ADMIN_ACCOUNT={$email} — невалидный email, подставляем стандартный admin@example.com");
                    $email = 'admin@example.com';
                }
            } else {
                $email = !empty($adminAccount) ? $adminAccount : text(
                    label: 'Введите EMAIL администратора',
                    default: 'admin@example.com',
                    required: true,
                    description: 'На этот email можно восстанавливать пароль (логин в админку).',
                    validate: fn(string $email): ?string => match (true) {
                        !filter_var($email, FILTER_VALIDATE_EMAIL) => 'Введите валидный email (формат name@example.ru).',
                        default => null,
                    }
                );
            }
            if (!empty($adminPassword) && is_string($adminPassword)) {
                $password = $adminPassword;
            } else {
                $password = Helper::guid(false);
            }
            $this->saveToEnv($envConfig);

            $installDriverOverrides = [
                'CACHE_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ];
            foreach ($installDriverOverrides as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
            Config::set('cache.default', 'array');
            Config::set('queue.default', 'sync');
            Config::set('session.driver', 'array');

            $this->call('config:cache');
            Artisan::call('cache:clear');
            $this->info('🔽 Шаг 1/4: Применяем миграции БД (создаём таблицы)...');
            Artisan::call("migrate", ['--force' => true]);
            $migOutput = Artisan::output();
            if (trim($migOutput) !== '') $this->line($migOutput);
            $this->info('✅ Шаг 1/4: Миграции БД завершены');
            $this->info('🔽 Шаг 2/4: Создаём или обновляем администратора...');
            if (!self::registerAdmin($email, $password, $this)) {
                $this->warn('⚠️  Админ не создан (существующая база? продолжаем...)');
            }
            $this->info('🔽 Шаг 3/4: Установка плагинов по умолчанию (OlcRTC + Telegram + core)...');
            // -----------------------------------------------------------------
            // Повторно запускаем миграции ПЕРЕД плагинами, чтобы гарантированно
            // существовала таблица v2_plugins (создаётся миграцией 2025_01_18).
            // Миграции, уже накатанные, повторно не запускаются (idempotent).
            // -----------------------------------------------------------------
            Artisan::call('migrate', ['--force' => true]);
            $migOut = Artisan::output();
            if (trim($migOut) !== '') {
                $this->line($migOut);
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('v2_plugins')) {
                $this->error('❌ ФАТАЛЬНАЯ ОШИБКА: таблица v2_plugins не создана. Плагин-система не запустится. Запустите вручную: php artisan migrate --force');
            } else {
                PluginManager::installDefaultPlugins();
                $this->info('✅ Шаг 3/4: Плагины по умолчанию установлены/синхронизированы');
            }

            // -----------------------------------------------------------------
            // Шаг 3.5/4: AUTO-SEED конфигурации (OlcRTC, ЮKassa, тарифы).
            //   Работает если: AUTO_SEED=1 (по умолчанию true при AUTO_INSTALL=1),
            //   либо если переданы соответствующие ENV-переменные.
            //   100% идемпотентно: проверяем существующие записи перед INSERT.
            // -----------------------------------------------------------------
            $autoSeedDefault = (bool) getenv('AUTO_INSTALL', false);
            $autoSeedEnv     = getenv('AUTO_SEED', false);
            if ($autoSeedEnv === false) {
                $autoSeed = $autoSeedDefault;
            } else {
                $autoSeed = filter_var($autoSeedEnv, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($autoSeed === null) {
                    $autoSeed = $autoSeedDefault;
                }
            }
            if ($autoSeed) {
                try {
                    $this->info('🔽 Шаг 3.5/4: AUTO-SEED конфигурации (OlcRTC + платёжка ЮKassa + 3 тарифы)');
                    $this->runAutoSeed();
                    $this->info('✅ Шаг 3.5/4: AUTO-SEED завершён');
                } catch (\Throwable $e) {
                    $this->warn('⚠️  AUTO-SEED пропущен (нефатально): ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
                }
            }

            // -----------------------------------------------------------------
            // React SPA админки (папка public/assets/admin):
            //   - Docker: entrypoint/Dockerfile клонирует xboard-admin-dist
            //   - Bare-metal: клонируем прямо здесь git clone https://github...
            // -----------------------------------------------------------------
            $adminDir  = public_path('assets/admin');
            $adminRepo = env('ADMIN_DIST_REPO', 'https://github.com/cedar2025/xboard-admin-dist.git');
            $manifest  = $adminDir . '/manifest.json';
            if (!is_dir($adminDir) || !is_file($manifest) || !filesize($manifest)) {
                $this->info('🔽 Шаг 4/4: Устанавливаем React-админку (xboard-admin-dist)...');
                if (!is_dir(dirname($adminDir))) {
                    @mkdir(dirname($adminDir), 0775, true);
                }
                $tmp = sys_get_temp_dir() . '/xboard-admin-dist-' . bin2hex(random_bytes(4));
                $cmd = "git clone --depth=1 " . escapeshellarg($adminRepo) . " " . escapeshellarg($tmp) . " 2>&1";
                @exec($cmd, $out, $code);
                if ($code === 0 && is_file($tmp . '/manifest.json')) {
                    File::cleanDirectory($adminDir);
                    File::copyDirectory($tmp, $adminDir);
                    File::deleteDirectories($adminDir . '/.git', $adminDir . '/.github');
                    $this->info('✅ Шаг 4/4: Админка установлена — ' . count(File::allFiles($adminDir)) . ' файлов');
                } else {
                    $this->warn("⚠️  Авто-установка админки провалилась (git exit={$code}).");
                    $this->warn('👉 Установите вручную в корне проекта команду:');
                    note("git clone --depth=1 {$adminRepo} public/assets/admin");
                }
                @File::deleteDirectory($tmp);
            } else {
                $this->info('✅ Шаг 4/4: Админка уже готова (React SPA): ' . count(File::allFiles($adminDir)) . ' файлов');
            }

            $this->info("");
            $this->info("  ╔════════════════════════════════════════════════════════╗");
            $this->info("  ║          ✅  УСТАНОВКА XBOARD VPN ПАНЕЛИ ЗАВЕРШЕНА      ║");
            $this->info("  ╚════════════════════════════════════════════════════════╝");
            $this->info("📧 Логин (email) администратора : {$email}");
            $this->info("🔑 Пароль администратора         : {$password}");
            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("🔗 URL админки (защищённый путь): http(s)://ваш-сайт/{$defaultSecurePath}");
            $this->warn('👉 СРАЗУ ПОСЛЕ ВХОДА: Меню профиля справа → «Сменить пароль».');
            $this->warn('👉 При Docker-развёртывании entrypoint контейнера сам докачивает React SPA админки (не требуется вручную).');
            $envConfig['INSTALLED'] = true;
            $this->saveToEnv($envConfig);
            foreach (array_keys($installDriverOverrides) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            Artisan::call('config:clear');
        } catch (\Exception $e) {
            $this->error($e);
            return 1;
        }
        return 0;
    }

    public static function registerAdmin($email, $password, $cmd = null)
    {
        try {
            $existing = User::where('email', $email)->first();
            if ($existing) {
                if ($existing->is_admin) {
                    if ($cmd) {
                        $cmd->info("Админ {$email} уже существует — обновляем пароль.");
                    }
                    $existing->password = password_hash($password, PASSWORD_DEFAULT);
                    return $existing->save();
                }
                if ($cmd) {
                    $cmd->warn("Email {$email} занят не-админом — используем случайный admin-email.");
                }
                $email = 'admin-' . substr(bin2hex(random_bytes(4)), 0, 6) . '@example.com';
            }
        } catch (\Throwable $e) {
            // Если таблицы ещё нет — продолжаем с обычным create
        }
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, 'Ошибка: пароль администратора должен содержать минимум 8 символов.');
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        return $user->save();
    }

    private function set_env_var($key, $value)
    {
        $value = !strpos($value, ' ') ? $value : '"' . $value . '"';
        $key = strtoupper($key);

        $envPath = app()->environmentFilePath();
        $contents = file_get_contents($envPath);

        if (preg_match("/^{$key}=[^\r\n]*/m", $contents, $matches)) {
            $contents = str_replace($matches[0], "{$key}={$value}", $contents);
        } else {
            $contents .= "\n{$key}={$value}\n";
        }

        return file_put_contents($envPath, $contents) !== false;
    }

    private function saveToEnv($data = [])
    {
        foreach ($data as $key => $value) {
            self::set_env_var($key, $value);
        }
        return true;
    }

    /**
     * AUTO-SEED конфигурации после установки.
     *  1) Плагин OlcRTC (olc_rtc): прописываем manager_url, manager_api_key из OLCRMGR_API_KEY env,
     *     default_dns=Yandex RF (77.88.8.8:53), trial 6h enabled etc.
     *  2) Платёжная система ЮKassa (yookassa): включаем, заполняем shop_id / secret_key,
     *     если не переданы через ENV YOOKASSA_SHOP_ID / YOOKASSA_SECRET_KEY - генерируем
     *     ДЕМО-ключ (test_ + hex), пользователь потом заменит в админке.
     *  3) ServerGroup «Все пользователи VPN» если нет → создаём.
     *  4) 3 тарифных плана Xboard (Базовый/Профи/Максимум) на 30/90/365 дней.
     *
     * @return void
     */
    /**
     * Прочитать ENV-значение из (1) getenv() → (2) $_ENV → (3) KEY= в .env/.env.local построчно.
     */
    private function _seedEnv(string $key): string
    {
        // 1. getenv local_only=TRUE (default): process-level env vars inherited from shell
        $v = getenv($key);
        if (is_string($v) && $v !== '') return trim($v);
        // 2. getenv local_only=FALSE: SAPI-wide (fallback, sometimes misses inherited vars)
        $v = getenv($key, false);
        if (is_string($v) && $v !== '') return trim($v);
        // 3. $_ENV / $_SERVER superglobals (EGPCS variables_order must include E)
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') return trim($_ENV[$key]);
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') return trim($_SERVER[$key]);
        // 4. Fallback: read KEY= lines from .env / .env.local on disk (for docker scenarios where env gets stripped)
        $candidates = [base_path('.env'), base_path('.env.local')];
        $prefix = $key . '=';
        foreach ($candidates as $f) {
            if (!File::exists($f)) continue;
            $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) continue;
            foreach ($lines as $line) {
                if (str_starts_with($line, $prefix)) return trim(substr($line, strlen($prefix)));
            }
        }
        return '';
    }

    private function runAutoSeed(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_plugins')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_payment')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_plan')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_server_group')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_knowledge')) {
            $this->warn('AUTO-SEED: одна из таблиц (plugins/payment/plan/server_group/knowledge) ещё не создана — пропускаем.');
            return;
        }

        $nowTs = time();

        // ------------------------------------------------------------------
        // 1) OlcRTC plugin: seed config JSON + ensure enabled
        // ------------------------------------------------------------------
        $olc = Plugin::where('code', 'olc_rtc')->first();
        if ($olc) {
            $olcCfg = is_string($olc->config) ? (json_decode($olc->config, true) ?: []) : ($olc->config ?? []);
            $olcApiKey = $this->_seedEnv('OLCRMGR_API_KEY');
            // --- Принудительно ПЕРЕЗАПИСЫВАЕМ ключевые поля (даже если уже были)
            $olcCfg['manager_url']       = 'http://olcrtc-manager:8080';
            $olcCfg['manager_api_key']   = (strlen($olcApiKey) >= 32) ? $olcApiKey : ($olcCfg['manager_api_key'] ?? 'change-me');
            $olcCfg['default_provider']  = 'jitsi';
            $olcCfg['default_transport'] = 'datachannel';
            $olcCfg['default_dns']       = '77.88.8.8:53';
            if (!array_key_exists('auth_token', $olcCfg) || !is_string($olcCfg['auth_token'])) $olcCfg['auth_token'] = '';
            $olcCfg['trial_hours']       = '6';
            $olcCfg['trial_enabled']     = true;
            $olcCfg['default_comment']   = 'OlcRTC VPN — подписка активирована';
            $olc->config = json_encode($olcCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$olc->is_enabled) $olc->is_enabled = true;
            $olc->updated_at = $nowTs;
            $olc->save();
            $this->info('  · OlcRTC плагин: manager_url + API-ключ + RU-DNS(77.88.8.8) + триал=6ч → прописаны и включен ✅');
        }

        // ------------------------------------------------------------------
        // 2) ЮKassa payment method: insert if missing → enable
        // ------------------------------------------------------------------
        $yooMethod = Payment::where('payment', 'yookassa')->first();
        if (!$yooMethod) {
            $shopIdEnv  = $this->_seedEnv('YOOKASSA_SHOP_ID');
            $secretEnv  = $this->_seedEnv('YOOKASSA_SECRET_KEY');
            $walletEnv  = $this->_seedEnv('YOOMONEY_WALLET');
            $yoomClient = $this->_seedEnv('YOOMONEY_CLIENT_ID');
            $yoomSecret = $this->_seedEnv('YOOMONEY_CLIENT_SECRET');
            // Оставляем только цифры в номере кошелька
            if ($walletEnv !== '') {
                $walletEnv = preg_replace('/\D+/', '', $walletEnv) ?? '';
            }
            if ($shopIdEnv === '' || !preg_match('/^\d{4,12}$/', $shopIdEnv)) {
                $shopIdEnv = '548791';
                $this->warn('  · ⚠️ YOOKASSA_SHOP_ID не передан → используем ДЕМО-значение 548791 (ЮKassa демо).');
            }
            if ($secretEnv === '' || !preg_match('/^(test_|live_)/', $secretEnv)) {
                $secretEnv = 'test_' . substr(bin2hex(random_bytes(30)), 0, 40);
                $this->warn('  · ⚠️ YOOKASSA_SECRET_KEY не передан → используем ВРЕМЕННЫЙ ДЕМО-ключ (замените в админке!)');
            }
            $yooConfig = [
                'shop_id'         => $shopIdEnv,
                'secret_key'      => $secretEnv,
                'payment_methods' => 'bank_card,sberbank,yoomoney,sbp,tinkoff_bank',
                'locale'          => 'ru-RU',
                'capture'         => true,
                'send_receipt'    => false,
                'tax_system_code' => '',
                'vat_code'        => '',
                'yoomoney_wallet'         => $walletEnv,
                'yoomoney_client_id'      => $yoomClient,
                'yoomoney_client_secret'  => $yoomSecret,
            ];
            $yooMethod = new Payment();
            $yooMethod->uuid      = Helper::randomChar(8);
            $yooMethod->payment   = 'yookassa';
            $yooMethod->name      = 'ЮKassa (ЮMoney / Сбербанк / СБП / карты)';
            $yooMethod->icon      = '💳';
            $yooMethod->config   = json_encode($yooConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $yooMethod->notify_domain = '';
            $yooMethod->handling_fee_fixed  = null;
            $yooMethod->handling_fee_percent = null;
            $yooMethod->enable     = true;
            $yooMethod->sort      = 1;
            $yooMethod->created_at = $nowTs;
            $yooMethod->updated_at = $nowTs;
            $yooMethod->save();
            $this->info('  · Платёжка ЮKassa: создана и включена ✅ (shop_id=' . $yooConfig['shop_id'] . ')');
        } elseif (!$yooMethod->enable) {
            $yooMethod->enable = true;
            $yooMethod->updated_at = $nowTs;
            $yooMethod->save();
            $this->info('  · Платёжка ЮKassa: включена ✅');
        }

        // ------------------------------------------------------------------
        // 3) ServerGroup «Все пользователи VPN» если нет
        // ------------------------------------------------------------------
        $group = ServerGroup::where('name', 'Все пользователи VPN')->first();
        $groupId = null;
        if (!$group) {
            $group = new ServerGroup();
            $group->name = 'Все пользователи VPN';
            $group->created_at = $nowTs;
            $group->updated_at = $nowTs;
            $group->save();
            $groupId = $group->id;
            $this->info('  · Группа серверов «Все пользователи VPN» создана, id=' . $groupId . ' ✅');
        } else {
            $groupId = $group->id;
        }

        // ------------------------------------------------------------------
        // 4) 3 тарифных плана Xboard (OlcRTC-enabled: Базовый / Профи / Максимум
        // ------------------------------------------------------------------
        $plansSeed = [
            [
                'name'     => '🥉 Базовый (30 дней)',
                'prices'   => [ Plan::PERIOD_MONTHLY   => 19900],
                'content'  => "OlcRTC VPN — 30 дней полного туннеля.\nПровайдер Jitsi (WebRTC datachannel).\nDNS: Яндекс 77.88.8.8. Пробный период 6 часов после регистрации.",
                'tags'     => ['популярный', 'VPN', 'OlcRTC'],
                'sort'     => 1,
            ],
            [
                'name'     => '🥈 Профи (90 дней) — выгода 16%',
                'prices'   => [ Plan::PERIOD_QUARTERLY => 49900],
                'content'  => "OlcRTC VPN — 3 месяца полного туннеля.\nПровайдер Jitsi, DNS Яндекс.\nАвтопродление по умолчанию.",
                'tags'     => ['выгодно', 'VPN', 'OlcRTC'],
                'sort'     => 2,
            ],
            [
                'name'     => '🥇 Максимум (1 год) — выгода 37%',
                'prices'   => [ Plan::PERIOD_YEARLY => 149900],
                'content'  => "OlcRTC VPN — 12 месяцев полного туннеля + приоритетная поддержка.\nJitsi WebRTC datachannel, DNS Яндекс.",
                'tags'     => ['лучший ценник', 'VPN', 'OlcRTC'],
                'sort'     => 3,
            ],
        ];
        $planCount = 0;
        foreach ($plansSeed as $seed) {
            $exists = Plan::where('name', $seed['name'])->exists();
            if ($exists) continue;
            $p = new Plan();
            $p->group_id = $groupId;
            $p->transfer_enable = 0;
            $p->name = $seed['name'];
            $p->content = $seed['content'];
            $p->prices = $seed['prices'];
            $p->tags = $seed['tags'];
            $p->show = true;
            $p->renew = true;
            $p->sell = true;
            $p->sort = $seed['sort'];
            $p->reset_traffic_method = Plan::RESET_TRAFFIC_NEVER;
            $p->created_at = $nowTs;
            $p->updated_at = $nowTs;
            $p->save();
            $planCount++;
        }
        if ($planCount > 0) {
            $this->info('  · Тарифы Xboard: создано ' . $planCount . ' тариф(а ✅ (Базовый/Профи/Максимум)');
        } else {
            $this->info('  · Тарифы Xboard: уже существуют (идемпотентно) — пропускаем ✅');
        }

        // ------------------------------------------------------------------
        // 5) База знаний (v2_knowledge): 4 статьи "как подключиться"
        // ------------------------------------------------------------------
        $lang = 'ru-RU';
        $category = 'Подключение (OlcRTC VPN)';
        $kbSeed = [
            [
                'sort'  => 1,
                'title' => '📘 Как подключиться: пошагово для новичка',
                'body'  =>
                    "📌 После оплаты тарифа перейдите в Личный кабинет → раздел «Моя подписка».\n\n".
                    "Шаг 1 — СКАЧАЙТЕ КЛИЕНТ:\n".
                    "  · 💻 Windows / macOS / Linux — OlcBox: https://github.com/alananisimov/olcbox/releases\n".
                    "  · 🤖 Android — owenclave: https://github.com/owenewans/owenclave/releases\n\n".
                    "Шаг 2 — СКОПИРУЙТЕ ВАШУ ССЫЛКУ (URI):\n".
                    "  В личном кабинете нажмите кнопку «📋 Копировать ключ».\n".
                    "  У вас в буфере обмена окажется строка вида: olcrtc://jitsi?datachannel@https://meet.jit.si/olcrtc-xxxxx#ХЕШ\$olc\n\n".
                    "Шаг 3 — ВСТАВЬТЕ В КЛИЕНТ:\n".
                    "  · OlcBox (Windows/Mac): запустите → нажмите ➕ «Добавить» → «Импорт URI» (или Ctrl+V) → Вставьте → ГОТОВО.\n".
                    "  · owenclave (Android): откройте → кнопка ➕ → «Import from clipboard» → Вставьте → ОК.\n\n".
                    "Шаг 4 — ПОДКЛЮЧИТЕСЬ: переключите тумблер ON / зелёная кнопка «Подключить».\n\n".
                    "✅ Проверка: зайдите на https://2ip.ru — у вас должен отобразиться новый IP-адрес.\n\n".
                    "💡 Пробный период (6 часов бесплатно) активируется сразу после регистрации, даже без оплаты!",
            ],
            [
                'sort'  => 2,
                'title' => '💻 Клиент OlcBox: Windows / macOS / Linux (инструкция)',
                'body'  =>
                    "🌐 Официальный репозиторий (скачивать только отсюда!):\n".
                    "   👉 https://github.com/alananisimov/olcbox/releases\n\n".
                    "Как скачать правильно:\n".
                    "  · Windows 10/11 — файл OlcBox_..._x64_en-US.msi (установщик) или .zip\n".
                    "  · macOS Apple Silicon (M1/M2/M3) — OlcBox_..._aarch64.dmg\n".
                    "  · macOS Intel — OlcBox_..._x64.dmg\n".
                    "  · Linux — OlcBox_..._amd64.deb (Debian/Ubuntu) или AppImage\n\n".
                    "Установка:\n".
                    "  — Запустите установщик, согласитесь с условиями, Next→Next→Finish.\n".
                    "  — При первом запуске Windows может спросить «Разрешить брандмауэр» — разрешите ДЛЯ ВСЕХ СЕТЕЙ.\n\n".
                    "Как добавить вашу подписку:\n".
                    "  1. Откройте Личный кабинет → «Моя подписка» → 📋 Копировать URI.\n".
                    "  2. В OlcBox сверху слева ➕ → Paste from Clipboard.\n".
                    "  3. Появится профиль «OlcRTC VPN». Нажмите ▶️ Connect.\n".
                    "  4. Верхний индикатор загорелся зелёным = VPN работает!\n\n".
                    "Где ещё полезные кнопки:\n".
                    "  · Кнопка ⚙️ (шестерёнка) рядом с профилем → копировать / экспорт yaml.\n".
                    "  · DNS уже настроен на 77.88.8.8 (Яндекс РФ), менять не нужно.",
            ],
            [
                'sort'  => 3,
                'title' => '🤖 Клиент owenclave: Android (рекомендуется для телефонов)',
                'body'  =>
                    "🎯 owenclave — ЛУЧШИЙ КЛИЕНТ ДЛЯ ANDROID: обновляется быстрее всех под новые версии OlcRTC, есть обход DPI.\n\n".
                    "🌐 Скачивать ТОЛЬКО отсюда:\n".
                    "   👉 https://github.com/owenewans/owenclave/releases\n\n".
                    "Что качать на Android:\n".
                    "  Файл с названием вида app-<ВЕРСИЯ>-release.apk (все телефоны с ARM64 — это 99% современных).\n".
                    "  Если телефон не устанавливает → включите в Настройки → Безопасность → «Неизвестные источники» (разрешить браузеру/файловому менеджеру).\n\n".
                    "Шаги подключения (30 секунд):\n".
                    "  1. Откройте браузер на телефоне → зайдите в Личный кабинет сайта → «Моя подписка».\n".
                    "  2. Нажмите «📋 Копировать ключ».\n".
                    "  3. Запустите owenclave → снизу справа синяя кнопка ➕\n".
                    "  4. Выберите «Import from clipboard» → всплывёт «Added 1 profile»\n".
                    "  5. Нажмите ▶️ «Play» / большой кружок справа → система спросит «Разрешить VPN-подключение» — ОК.\n\n".
                    "✅ Когда значок 🔑 в статус-баре сверху появился — всё подключено!\n\n".
                    "💡 Совет: включите в настройках owenclave «Always-on VPN» + «Block connections without VPN» чтобы ничего не утекало.",
            ],
            [
                'sort'  => 4,
                'title' => '🔑 Где взять ключ подключения в кабинете (пошагово)',
                'body'  =>
                    "✅ ОТВЕТ: Ключ `olcrtc://jitsi?datachannel@https://meet...` = это ВАША ССЫЛКА ДЛЯ ПОДКЛЮЧЕНИЯ — У КАЖДОГО ПОЛЬЗОВАТЕЛЯ ОНА СВОЯ, УНИКАЛЬНАЯ!\n\n".
                    "Где найти ключ на сайте (4 клика):\n".
                    "  1. Войдите в Личный кабинет /#/login (email + пароль при регистрации).\n".
                    "  2. КУПИТЕ тариф (раздел «Тарифы» → 🥉Базовый 199₽) — или воспользуйтесь 6-часовым пробным периодом (сразу после регистрации).\n".
                    "  3. Сразу после оплаты → перейдите вверху меню «👉 МОЯ ПОДПИСКА» (англ. Subscription / Sub).\n".
                    "  4. На странице вы увидите:\n".
                    "        ├─ БЛОК «🔑 МОЙ КЛЮЧ / URI» = длинная строка вида olcrtc://jitsi?datachannel@https://meet...\n".
                    "        ├─ РЯДОМ КНОПКА «📋 СКОПИРОВАТЬ» (нажмите 1 раз — ключ в буфере обмена).\n".
                    "        └─ Подсказка зелёным цветом: ✅ КЛЮЧ ВЫДАН! Вставьте в OlcBox/owenclave → Connect.\n\n".
                    "🔑 ЕСЛИ КЛЮЧ НЕ ПОЯВИЛСЯ СРАЗУ (пустая строка):\n".
                    "  · Ждите 1–3 минуты (olcrtc-менеджер создаёт инстанс) → обновите страницу.\n".
                    "  · Нажмите синюю кнопку «🔄 Пересоздать инстанс» → подождите 30 секунд → F5.\n".
                    "  · Проверьте, что тариф оплачен (или бесплатный 6-часовой триал не истёк).\n".
                    "  · Если прошло >5 минут и нет ключа — напишите в поддержку, мы пересоздадим вручную.\n\n".
                    "💡 ПРОВЕРКА: вставьте скопированный ключ в OlcBox (Windows) или owenclave (Android) и нажмите Подключить. Если загорелся зелёный индикатор или 🔑 в статус-баре — всё работает!",
            ],
            [
                'sort'  => 5,
                'title' => '❌ НЕ ЗАПУСКАЙТЕ «xboard-node install.sh» (он не для OlcRTC VPN!)',
                'body'  =>
                    "⛔ ВАЖНО: Команда вида `curl .../xboard-node/dev/install.sh | sudo bash -s -- --mode machine --panel URL --token XXX --machine-id N` — это АВТОРСКИЙ cedar2025/xboard-node — УСТАНОВКА СТОРОННЕГО ДЕМОНА, КОТОРЫЙ OlcRTC VPN НЕ ИСПОЛЬЗУЕТ.\n\n".
                    "❓ Зачем он вообще есть в админке в меню «Добавить сервер»?\n".
                    "→ Потому что Xboard по умолчанию рассчитан на V2Ray/Xray/Shadowsocks протоколы (50+ типов узлов). OlcRTC — это НОВЫЙ ОТДЕЛЬНЫЙ WebRTC-протокол, ЕМУ УЗЛЫ НЕ НУЖНЫ (вместо них работает docker-контейнер `olcrtc-manager` внутри compose.yaml — запускается АВТОМАТИЧЕСКИ через install.sh).\n\n".
                    "❓ Что будет, если его запустить? (как в ваших логах Xboard-src.txt)\n".
                    "→ ДЕМОН xboard-node сразу попытается открыть HTTPS 443 на панели → упадёт с `dial tcp 78.17.198.236:443: connect: connection refused`. Он будет бесконечно рестартовать, забивать логи и кушать RAM.\n".
                    "→ Лечится автоматически: следующий запуск install.sh УДАЛЯЕТ его (обнаруживает xboard-node.service → stop/disable/mask → rm бинари/конфиги).\n\n".
                    "🧹 ОДНОСТРОЧНИК УДАЛЕНИЯ xboard-node РУКАМИ СЕЙЧАС:\n".
                    "```\n".
                    "sudo systemctl stop xboard-node.service 2>/dev/null; sudo systemctl disable xboard-node.service 2>/dev/null; sudo systemctl mask xboard-node.service 2>/dev/null; sudo systemctl daemon-reload 2>/dev/null; sudo rm -f /etc/systemd/system/xboard-node.service /etc/systemd/system/multi-user.target.wants/xboard-node.service /usr/local/bin/xbctl /usr/local/bin/xboard-node; sudo rm -rf /etc/xboard-node /var/lib/xboard-node /var/log/xboard-node 2>/dev/null; (sudo deluser --remove-home xboard-node 2>/dev/null || sudo userdel -r xboard-node 2>/dev/null || true); echo '✅ xboard-node удалён'\n".
                    "```\n\n".
                    "✅ ЧТО ДЕЛАТЬ ВМЕСТО ЭТОГО? Ничего! install.sh уже поднимает 3 docker-контейнера:\n".
                    "  1) xboard (PHP Laravel, Caddy, Octane, Horizon, WS-сервер)\n".
                    "  2) redis (кеш/сессии)\n".
                    "  3) olcrtc-manager (Go/Gin, выдаёт персональные URI olcrtc:// ключей)\n\n".
                    "Если эти 3 контейнера UP (docker compose ps) — VPN-сервис ПОЛНОСТЬЮ РАБОТАЕТ ✅. Никаких «машин» и «узлов» в админке для OlcRTC добавлять не нужно.",
            ],
            [
                'sort'  => 6,
                'title' => '❓ FAQ: часто задаваемые вопросы',
                'body'  =>
                    "🔹 В: А это бесплатно?\n".
                    "О: Первые 6 часов после регистрации — БЕСПЛАТНЫЙ пробный период (сразу после регистрации). Потом купите любой тариф в разделе «Тарифы».\n\n".
                    "🔹 В: Подключился, но сайты не открываются / «нет интернета». Что делать?\n".
                    "О:\n".
                    "  1. Закройте клиент, запустите заново → Подключить.\n".
                    "  2. Перезагрузите телефон/ПК (помогает в 50% случаев).\n".
                    "  3. Попробуйте подключиться в другой сети (мобильный интернет вместо Wi-Fi или наоборот).\n".
                    "  4. Если ничего не помогло — в Личном кабинете нажмите «🔄 Пересоздать инстанс» и повторите подключение через 2 минуты.\n\n".
                    "🔹 В: Работает ли это в моей стране? Вконтакте/Ютуб открывается?\n".
                    "О: Да, подключается через WebRTC (похож на Zoom/Jitsi) — практически не блокируется провайдерами.\n\n".
                    "🔹 В: Как оплатить?\n".
                    "О: Картой (МИР/Visa/MasterCard), СберПей, СБП, ЮMoney через ЮKassa — всё стандартно, приходит чек.\n\n".
                    "🔹 В: Как отключить автопродление?\n".
                    "О: Личный кабинет → Тариф → кнопка «Отменить автопродление» (пока тариф активен, можно вернуть остаток дней в кредит).\n\n".
                    "🔹 В: Сколько устройств одновременно?\n".
                    "О: Неограниченно с одним URI (но чем больше устройств, тем ниже скорость на каждое).",
            ],
        ];
        $kbCount = 0;
        foreach ($kbSeed as $row) {
            $exists = Knowledge::where('title', $row['title'])->exists();
            if ($exists) continue;
            $k = new Knowledge();
            $k->language = $lang;
            $k->category = $category;
            $k->title    = $row['title'];
            $k->body     = $row['body'];
            $k->sort     = $row['sort'];
            $k->show     = true;
            $k->created_at = $nowTs;
            $k->updated_at = $nowTs;
            $k->save();
            $kbCount++;
        }
        if ($kbCount > 0) {
            $this->info('  · База знаний: создано ' . $kbCount . ' статей RU ✅ (OlcBox, owenclave, FAQ)');
        } else {
            $this->info('  · База знаний: уже существуют (идемпотентно) — пропускаем ✅');
        }

        // ------------------------------------------------------------------
        // 6) OlcRTC-ONLY MODE LOCKDOWN: v2_settings + cleanup non-target
        //    plans/payments/server rows.  Runs idempotently every AUTO_SEED
        //    so re-installs converge to "OlcRTC VPN only" product state.
        // ------------------------------------------------------------------
        if (Schema::hasTable('v2_settings')) {
            $settings = [
                'olcrtc_only_mode'      => 1,
                'subscribe_disabled'    => 1,
                'server_create_disabled'=> 1,
            ];
            foreach ($settings as $sKey => $sVal) {
                $row = Setting::where('name', $sKey)->first();
                if (!$row) {
                    $row = new Setting();
                    $row->name = $sKey;
                    $row->created_at = $nowTs;
                }
                if ((int)$row->value !== (int)$sVal) {
                    $row->value = (string)$sVal;
                    $row->updated_at = $nowTs;
                    $row->save();
                }
            }
            $this->info('  · v2_settings: olcrtc_only_mode=1, subscribe_disabled=1, server_create_disabled=1 ✅');
        }

        // Hide non-OlcRTC plans (anything that isn't our 3 seeded titles)
        if (Schema::hasTable('v2_plan')) {
            $targetPlanNames = [
                '🥉 Базовый (30 дней)',
                '🥈 Профи (90 дней) — выгода 16%',
                '🥇 Максимум (1 год) — выгода 37%',
            ];
            $hiddenCount = Plan::whereNotIn('name', $targetPlanNames)
                ->where('show', '=', 1)
                ->update(['show' => 0, 'updated_at' => $nowTs]);
            // Force-overwrite tags/capacity/device_limit for our 3 plans
            Plan::whereIn('name', $targetPlanNames)->update([
                'tags'          => ['OlcRTC', 'VPN', 'WebRTC'],
                'capacity'      => 999999,
                'device_limit'  => 0,
                'transfer_enable' => 0,
                'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
                'renew'         => 1,
                'sell'          => 1,
                'updated_at'    => $nowTs,
            ]);
            if ($hiddenCount > 0) {
                $this->info('  · Тарифы: скрыто ' . $hiddenCount . ' не-OlcRTC планов (show=0) ✅');
            } else {
                $this->info('  · Тарифы: 3 OlcRTC-плана показываются, лишних не обнаружено ✅');
            }
        }

        // Disable non-YooKassa payment methods
        if (Schema::hasTable('v2_payment')) {
            $disabledCount = Payment::where('payment', '<>', 'yookassa')
                ->where('enable', '=', 1)
                ->update(['enable' => 0, 'updated_at' => $nowTs]);
            if ($disabledCount > 0) {
                $this->info('  · Оплаты: отключено ' . $disabledCount . ' не-ЮKassa шлюзов ✅');
            } else {
                $this->info('  · Оплаты: только ЮKassa включена (idempotently) ✅');
            }
        }

        // Delete all legacy V2Ray server/node/machine/log/stat rows.
        // We preserve v2_server_group rows (group "Все пользователи VPN" needed for plan group_id).
        $deletedServers = 0;
        foreach ([
            'v2_server',
            'v2_server_machine',
            'v2_server_log',
            'v2_server_stat',
        ] as $tbl) {
            if (Schema::hasTable($tbl)) {
                try {
                    $deletedServers += DB::table($tbl)->delete();
                } catch (\Throwable $e) {
                    $this->warn('  · ⚠️ Не удалось очистить ' . $tbl . ': ' . $e->getMessage());
                }
            }
        }
        if ($deletedServers > 0) {
            $this->info('  · Серверы: очищено ' . $deletedServers . ' legacy-строк (V2Ray/SS узлы/машины/логи) ✅');
        } else {
            $this->info('  · Серверы: таблицы узлов пусты (OlcRTC без узлов) — ОК ✅');
        }
    }

    function getEnvValue($key, $default = null)
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(base_path());
        $dotenv->load();

        return Env::get($key, $default);
    }

    /**
     * Конфигурация БД SQLite (рекомендуется).
     * Создаёт пустой .db-файл в .docker/.data/xboard.sqlite, проверяет PDO,
     * при обнаружении уже существующих таблиц спрашивает: очистить или отменить.
     *
     * @return array|null  — массив ENV конфига; или null = пользователь отменил очистку
     */
    private function configureSqlite(): ?array
    {
        $sqliteFile = '.docker/.data/xboard.sqlite';
        if (!file_exists(base_path($sqliteFile))) {
            // Создаём пустой файл БД + папку для него (если не было)
            if (!is_dir(dirname(base_path($sqliteFile)))) {
                @mkdir(dirname(base_path($sqliteFile)), 0775, true);
            }
            if (!touch(base_path($sqliteFile))) {
                $this->info("📦 Новый файл SQLite БД СОЗДАН: $sqliteFile");
            }
        }

        $envConfig = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $sqliteFile,
            'DB_HOST' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ];

        try {
            Config::set("database.default", 'sqlite');
            $resolvedDb = $envConfig['DB_DATABASE'];
            if (!str_starts_with($resolvedDb, '/')) {
                $resolvedDb = base_path($resolvedDb);
            }
            Config::set("database.connections.sqlite.database", $resolvedDb);
            DB::purge('sqlite');
            DB::connection('sqlite')->getPdo();

            $tables = DB::connection('sqlite')->getPdo()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
            if (!blank($tables)) {
                $doWipe = false;
                if (isset($GLOBALS['__db_wipe_override']) && $GLOBALS['__db_wipe_override']) {
                    $doWipe = true;
                } elseif ((bool) getenv('DB_FORCE_WIPE', false)) {
                    $doWipe = true;
                } elseif ((bool) getenv('AUTO_INSTALL', false)) {
                    $doWipe = false;
                } else {
                    if (confirm(label: '⚠️  Обнаружена УЖЕ ЗАПОЛНЕННАЯ SQLite БД. ОЧИСТИТЬ её (все данные удалятся) для переустановки?', default: false, yes: '🗑️ Да, очистить всё', no: '❌ Нет, отменить установку')) {
                        $doWipe = true;
                    } else {
                        return null;
                    }
                }
                if ($doWipe) {
                    $this->info('🗑️ Очищаем БД SQLite (запрос подтверждён)...');
                    $this->call('db:wipe', ['--force' => true]);
                    $this->info('✅ БД SQLite очищена (все таблицы удалены)');
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ Не удалось подключиться к SQLite БД: " . $e->getMessage());
            return null;
        }

        return $envConfig;
    }

    /**
     * Конфигурация БД MySQL 5.7+ / MariaDB 10.x (отдельный сервер).
     * Цикл: спрашиваем креденшелы → ping PDO → если БД заполнена → подтверждаем wipe.
     *
     * @return array — массив ENV конфига
     */
    private function configureMysql(): array
    {
        while (true) {
            $envConfig = [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => text(label: 'Введите HOST (IP/домен) MySQL-сервера', default: '127.0.0.1', required: true, description: 'Docker compose = mysql; внешний = IP'),
                'DB_PORT' => text(label: 'Введите PORT MySQL', default: '3306', required: true, description: 'стандартный порт 3306'),
                'DB_DATABASE' => text(label: 'Введите ИМЯ базы данных MySQL', default: 'xboard', required: true, description: 'должна уже быть создана: CREATE DATABASE xboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),
                'DB_USERNAME' => text(label: 'Введите ИМЯ ПОЛЬЗОВАТЕЛЯ MySQL', default: 'root', required: true),
                'DB_PASSWORD' => text(label: 'Введите ПАРОЛЬ пользователя MySQL', default: '', required: false),
            ];

            try {
                Config::set("database.default", 'mysql');
                Config::set("database.connections.mysql.host", $envConfig['DB_HOST']);
                Config::set("database.connections.mysql.port", $envConfig['DB_PORT']);
                Config::set("database.connections.mysql.database", $envConfig['DB_DATABASE']);
                Config::set("database.connections.mysql.username", $envConfig['DB_USERNAME']);
                Config::set("database.connections.mysql.password", $envConfig['DB_PASSWORD']);
                DB::purge('mysql');
                DB::connection('mysql')->getPdo();

                if (!blank(DB::connection('mysql')->select('SHOW TABLES'))) {
                    if (confirm(label: '⚠️  База MySQL ЗАПОЛНЕНА (уже есть таблицы). ОЧИСТИТЬ? (все данные удалятся)', default: false, yes: '🗑️ Да, очистить', no: '❌ Нет, ввести параметры заново')) {
                        $this->info('🗑️ Очищаем MySQL БД...');
                        $this->call('db:wipe', ['--force' => true]);
                        $this->info('✅ БД MySQL очищена.');
                        return $envConfig;
                    } else {
                        continue; // Запрашиваем креденшелы заново (цикл)
                    }
                }

                return $envConfig;
            } catch (\Exception $e) {
                $this->error("❌ Не удалось подключиться к MySQL. Ошибка: " . $e->getMessage());
                $this->info('↻ Введите параметры подключения к MySQL заново (Ctrl+C = выход).');
            }
        }
    }

    /**
     * Конфигурация БД PostgreSQL 13+ (отдельный сервер).
     * Аналогично MySQL: ping PDO → существующие таблицы → confirm wipe.
     *
     * @return array — массив ENV конфига
     */
    private function configurePostgresql(): array
    {
        while (true) {
            $envConfig = [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => text(label: 'Введите HOST (IP/домен) PostgreSQL-сервера', default: '127.0.0.1', required: true, description: 'Docker compose = postgres; внешний = IP'),
                'DB_PORT' => text(label: 'Введите PORT PostgreSQL', default: '5432', required: true, description: 'стандартный порт 5432'),
                'DB_DATABASE' => text(label: 'Введите ИМЯ базы данных PostgreSQL', default: 'xboard', required: true, description: 'заранее CREATE DATABASE xboard'),
                'DB_USERNAME' => text(label: 'Введите ИМЯ ПОЛЬЗОВАТЕЛЯ PostgreSQL', default: 'postgres', required: true),
                'DB_PASSWORD' => text(label: 'Введите ПАРОЛЬ пользователя PostgreSQL', default: '', required: false),
            ];

            try {
                Config::set("database.default", 'pgsql');
                Config::set("database.connections.pgsql.host", $envConfig['DB_HOST']);
                Config::set("database.connections.pgsql.port", $envConfig['DB_PORT']);
                Config::set("database.connections.pgsql.database", $envConfig['DB_DATABASE']);
                Config::set("database.connections.pgsql.username", $envConfig['DB_USERNAME']);
                Config::set("database.connections.pgsql.password", $envConfig['DB_PASSWORD']);
                DB::purge('pgsql');
                DB::connection('pgsql')->getPdo();

                // pg_catalog.pg_tables: все таблицы в схеме public
                $tables = DB::connection('pgsql')->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                if (!blank($tables)) {
                    if (confirm(label: '⚠️  База PostgreSQL ЗАПОЛНЕНА (уже есть таблицы). ОЧИСТИТЬ? (все данные удалятся)', default: false, yes: '🗑️ Да, очистить', no: '❌ Нет, ввести параметры заново')) {
                        $this->info('🗑️ Очищаем БД PostgreSQL...');
                        $this->call('db:wipe', ['--force' => true]);
                        $this->info('✅ БД PostgreSQL очищена.');
                        return $envConfig;
                    } else {
                        continue; // Цикл: ввод заново
                    }
                }

                return $envConfig;
            } catch (\Exception $e) {
                $this->error("❌ Не удалось подключиться к PostgreSQL. Ошибка: " . $e->getMessage());
                $this->info('↻ Введите параметры подключения к PostgreSQL заново (Ctrl+C = выход).');
            }
        }
    }
}
