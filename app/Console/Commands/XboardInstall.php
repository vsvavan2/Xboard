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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
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
            // Even if already installed → we MUST still re-seed (OlcRTC-only flags,
            // plan hide/show, non-YooKassa payments disable) — because install.sh
            // may be re-run to apply new AUTO_SEED changes without DB wipe.
            if ($alreadyInstalled) {
                try {
                    $autoSeed = (bool) getenv('AUTO_SEED', false);
                    if ($autoSeed) {
                        $this->info('🔽 Повторный AUTO-SEED (OlcRTC-only режим) — применяем новые настройки...');
                        $this->runAutoSeed();
                        $this->info('✅ Повторный AUTO-SEED завершён.');
                    }
                } catch (\Throwable $e) {
                    $this->warn('⚠️  Re-seed skipped: ' . $e->getMessage());
                }
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

            // -----------------------------------------------------------------
            // НЕ перезаписываем существующий .env целиком.
            // install.sh и оператор прописывают в .env интеграционные переменные
            // (OLCRMGR_API_KEY, OLCRMGR_URL, XBOARD_PORT, ADMIN_*, YOOMONEY_*,
            // AUTO_*, …) рядом с DB/Redis. Раньше безусловный copy() затирал файл
            // шаблоном .env.example и молча терял их — после следующего
            // `docker compose up -d` olcrtc-manager получал дефолтный ключ
            // "please-change-me-in-env-file", и все /api/olcrtc/* падали с HTTP 401.
            // Теперь: создаём .env из примера только если файла нет, а иначе
            // дописываем лишь отсутствующие ключи, сохраняя всё существующее.
            $envPath     = base_path() . '/.env';
            $envExample  = base_path() . '/.env.example';
            if (!File::exists($envPath)) {
                if (!@copy($envExample, $envPath)) {
                    abort(500, 'Не удалось создать .env из .env.example. Проверьте права на запись в корневую папку проекта (chown/chmod).');
                }
            } elseif (File::exists($envExample)) {
                $envBody  = (string) @file_get_contents($envPath);
                $haveKeys = [];
                foreach (preg_split('/\r\n|\r|\n/', $envBody) ?: [] as $line) {
                    if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m)) {
                        $haveKeys[$m[1]] = true;
                    }
                }
                $missing = '';
                foreach (preg_split('/\r\n|\r|\n/', (string) @file_get_contents($envExample)) ?: [] as $line) {
                    if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) && !isset($haveKeys[$m[1]])) {
                        $missing .= $line . PHP_EOL;
                    }
                }
                if ($missing !== '') {
                    @file_put_contents($envPath, rtrim($envBody, "\r\n") . PHP_EOL . $missing, LOCK_EX);
                }
            }
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

    /**
     * ЮKassa разрешено включать только с боевым ключом (live_...).
     * Тестовый/пустой secret_key на боевом API возвращает 401, и ЛК показывает
     * пользователю «Похоже, это техническая ошибка» при выборе способа оплаты,
     * поэтому такой шлюз автоматически держится выключенным.
     */
    private function _yookassaIsLive($config): bool
    {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        $secret = is_array($config) ? (string) ($config['secret_key'] ?? '') : '';
        return $secret !== '' && stripos($secret, 'live_') === 0;
    }

    private function runAutoSeed(): void
    {
        // Wire up a default output driver when $this->output is null. This
        // happens when runAutoSeed() is invoked directly via ReflectionMethod
        // from the entrypoint shell script (plugin self-heal convergence sweep),
        // because `new XboardInstall()` does not bootstrap artisan's console
        // I/O stack. Without this guard every $this->info()/warn()/line()
        // throws "Call to a member function writeln() on null" inside
        // InteractsWithIO and the seed sweep never runs.
        if ($this->output === null) {
            try {
                $this->output = class_exists(ConsoleOutput::class, false)
                    ? new ConsoleOutput()
                    : new NullOutput();
            } catch (\Throwable $e) {
                $this->output = new NullOutput();
            }
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_plugins')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_payment')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_plan')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_server_group')
            || !\Illuminate\Support\Facades\Schema::hasTable('v2_knowledge')) {
            // Graceful fallback: when invoked outside of the artisan command
            // lifecycle (i.e. ReflectionMethod::invoke from entrypoint tinker)
            // the `$this->output` field is not wired up yet and calling
            // `$this->warn()` / `$this->info()` would throw:
            //   "Call to a member function writeln() on null"
            // inside InteractsWithIO. We swallow the exception silently so the
            // no-table early return never surfaces an error in logs.
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
            $olcCfg['trial_hours']       = '72';
            $olcCfg['trial_enabled']     = true;
            $olcCfg['default_comment']   = 'OlcRTC VPN — подписка активирована';
            $olc->config = json_encode($olcCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$olc->is_enabled) $olc->is_enabled = true;
            $olc->updated_at = $nowTs;
            $olc->save();
            $this->info('  · OlcRTC плагин: manager_url + API-ключ + RU-DNS(77.88.8.8) + триал=72ч (3 дня) → прописаны и включен ✅');
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
            $yooMethod->enable     = $this->_yookassaIsLive($yooConfig);
            $yooMethod->sort      = 1;
            $yooMethod->created_at = $nowTs;
            $yooMethod->updated_at = $nowTs;
            $yooMethod->save();
            $this->info(
                $yooMethod->enable
                    ? '  · Платёжка ЮKassa: создана и включена ✅ (shop_id=' . $yooConfig['shop_id'] . ')'
                    : '  · Платёжка ЮKassa: создана ВЫКЛЮЧЕННОЙ ⚠️ (secret_key не боевой — включите после ввода live_-ключа)'
            );
        } else {
            // Включаем ЮKassa автоматически только при боевом ключе; с test_/пустым
            // ключом боевой API отдаёт 401 и ЛК показывает «техническая ошибка».
            $live = $this->_yookassaIsLive($yooMethod->config);
            if ($live && !(bool) $yooMethod->enable) {
                $yooMethod->enable = 1;
                $yooMethod->updated_at = $nowTs;
                $yooMethod->save();
                $this->info('  · Платёжка ЮKassa: боевой ключ найден → включена ✅');
            } elseif (!$live && (bool) $yooMethod->enable) {
                $yooMethod->enable = 0;
                $yooMethod->updated_at = $nowTs;
                $yooMethod->save();
                $this->warn('  · Платёжка ЮKassa: secret_key не боевой (test_/пусто) → выключена, чтобы оплата не падала ⚠️');
            }
        }

        // ------------------------------------------------------------------
        // 2b) ЮMoney (напрямую на кошелёк, БЕЗ ИП/организации) — метод `yoomoney`
        //     Создаём строку оплаты, если её ещё нет. Включаем ТОЛЬКО когда
        //     заполнены и кошелёк (YOOMONEY_WALLET), и секрет уведомлений
        //     (YOOMONEY_NOTIFICATION_SECRET) — иначе метод будет виден в админке,
        //     но выключен (чтобы не показывать пользователям нерабочую оплату).
        // ------------------------------------------------------------------
        $yooMoneyMethod = Payment::where('payment', 'yoomoney')->first();
        if (!$yooMoneyMethod) {
            $walletEnv = $this->_seedEnv('YOOMONEY_WALLET');
            $notifySecretEnv = $this->_seedEnv('YOOMONEY_NOTIFICATION_SECRET');
            $walletEnv = $walletEnv !== '' ? (preg_replace('/\D+/', '', $walletEnv) ?? '') : '';
            $ymConfig = [
                'wallet'       => $walletEnv,
                'secret'       => $notifySecretEnv,
                'payment_type' => '',
                'success_url'  => '',
            ];
            $yooMoneyMethod = new Payment();
            $yooMoneyMethod->uuid      = Helper::randomChar(8);
            $yooMoneyMethod->payment   = 'yoomoney';
            $yooMoneyMethod->name      = 'ЮMoney (оплата на кошелёк)';
            $yooMoneyMethod->icon      = '🟣';
            $yooMoneyMethod->config    = json_encode($ymConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $yooMoneyMethod->notify_domain = '';
            $yooMoneyMethod->handling_fee_fixed  = null;
            $yooMoneyMethod->handling_fee_percent = null;
            $yooMoneyMethod->enable    = ($walletEnv !== '' && $notifySecretEnv !== '');
            $yooMoneyMethod->sort      = 2;
            $yooMoneyMethod->created_at = $nowTs;
            $yooMoneyMethod->updated_at = $nowTs;
            $yooMoneyMethod->save();
            if ($yooMoneyMethod->enable) {
                $this->info('  · Платёжка ЮMoney (кошелёк): создана и включена ✅ (wallet=' . $walletEnv . ')');
            } else {
                $this->info('  · Платёжка ЮMoney (кошелёк): создана, но ВЫКЛЮЧЕНА — заполните номер кошелька и секрет уведомлений в админке → Платежи → ЮMoney ✅');
            }
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
                'content'  => "OlcRTC VPN — 30 дней полного туннеля.\nПровайдер Jitsi (WebRTC datachannel).\nDNS: Яндекс 77.88.8.8. Пробный период 3 дня (72 часа) после регистрации.",
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
            try {
                $p = new Plan();
                $p->group_id = $groupId;
                // Only set columns that actually exist in schema
                // (Schema guards for optional recent migrations: device_limit/tags)
                if (Schema::hasColumn('v2_plan', 'transfer_enable')) $p->transfer_enable = 0;
                $p->name = $seed['name'];
                $p->content = $seed['content'];
                $p->prices = $seed['prices'];
                if (Schema::hasColumn('v2_plan', 'tags')) $p->tags = $seed['tags'];
                if (Schema::hasColumn('v2_plan', 'show')) $p->show = true;
                if (Schema::hasColumn('v2_plan', 'renew')) $p->renew = true;
                if (Schema::hasColumn('v2_plan', 'sell')) $p->sell = true;
                $p->sort = $seed['sort'];
                if (Schema::hasColumn('v2_plan', 'reset_traffic_method')) {
                    $p->reset_traffic_method = Plan::RESET_TRAFFIC_NEVER;
                }
                if (Schema::hasColumn('v2_plan', 'capacity_limit')) $p->capacity_limit = 999999;
                if (Schema::hasColumn('v2_plan', 'device_limit')) $p->device_limit = 0;
                $p->created_at = $nowTs;
                $p->updated_at = $nowTs;
                $p->save();
                $planCount++;
            } catch (\Throwable $e) {
                $this->warn('  · ⚠️ Не удалось создать тариф «' . $seed['name'] . '»: ' . $e->getMessage());
            }
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
                'body'  => <<<'KB1'
📌 После оплаты тарифа перейдите в **Личный кабинет → раздел «Моя подписка»**.

---

### Шаг 1 — Скачайте клиент

Новые версии всегда лежат на GitHub во вкладке **Assets** на странице релиза:

- 💻 **Windows / macOS / Linux** — OlcBox → <a href="https://github.com/alananisimov/olcbox/releases" target="_blank" rel="noopener noreferrer">открыть страницу загрузок OlcBox</a>
- 🤖 **Android** — owenclave → <a href="https://github.com/owenewans/owenclave/releases" target="_blank" rel="noopener noreferrer">открыть страницу загрузок owenclave</a>

**Прямые ссылки на последние версии** (если не хотите искать файл на странице релиза):

💻 **OlcBox 1.0.129 — компьютеры:**

- <a href="https://github.com/alananisimov/olcbox/releases/download/1.0.129-nightly/Olcbox-1.0.129-windows-amd64.exe" target="_blank" rel="noopener noreferrer">Windows — установщик (.exe)</a>
- <a href="https://github.com/alananisimov/olcbox/releases/download/1.0.129-nightly/Olcbox-1.0.129-windows-amd64-portable.zip" target="_blank" rel="noopener noreferrer">Windows — портативная сборка (.zip)</a>
- <a href="https://github.com/alananisimov/olcbox/releases/download/1.0.129-nightly/Olcbox-1.0.129-macos-arm64.dmg" target="_blank" rel="noopener noreferrer">macOS — Apple Silicon M1/M2/M3 (.dmg)</a>
- <a href="https://github.com/alananisimov/olcbox/releases/download/1.0.129-nightly/Olcbox-1.0.129-macos-amd64.dmg" target="_blank" rel="noopener noreferrer">macOS — Intel (.dmg)</a>
- <a href="https://github.com/alananisimov/olcbox/releases/download/1.0.129-nightly/Olcbox-1.0.129-linux-amd64.AppImage" target="_blank" rel="noopener noreferrer">Linux — AppImage</a>

🤖 **owenclave 0.17.59 — Android:**

- <a href="https://github.com/owenewans/owenclave/releases/download/v0.17.59/Owenclave-0.17.59-arm64-v8a.apk" target="_blank" rel="noopener noreferrer">arm64-v8a — для 99% современных телефонов</a>
- <a href="https://github.com/owenewans/owenclave/releases/download/v0.17.59/Owenclave-0.17.59-armeabi-v7a.apk" target="_blank" rel="noopener noreferrer">armeabi-v7a — для старых 32-битных устройств</a>
- <a href="https://github.com/owenewans/owenclave/releases/download/v0.17.59/Owenclave-0.17.59-x86_64.apk" target="_blank" rel="noopener noreferrer">x86_64 — для эмуляторов</a>

---

### Шаг 2 — Скопируйте вашу ссылку (ключ)

1. В Личном кабинете нажмите кнопку **«📋 Копировать ключ»**.
2. В буфере окажется строка вида:

`olcrtc://jitsi?datachannel@https://meet.mamba.group/olcrtc-xxxxx#ХЕШ$olc`

---

### Шаг 3 — Вставьте ключ в клиент

- **OlcBox** (Windows / macOS / Linux): запустите → ➕ «Добавить» → «Импорт URI» (или Ctrl+V) → вставьте → готово.
- **owenclave** (Android): откройте → кнопка ➕ → «Import from clipboard» → вставьте → ОК.

---

### Шаг 4 — Подключитесь

Переключите тумблер **ON** (или нажмите зелёную кнопку «Подключить»).

✅ **Проверка:** откройте <a href="https://2ip.ru" target="_blank" rel="noopener noreferrer">2ip.ru</a> — должен отобразиться новый IP-адрес.

---

💡 **Пробный период:** 3 дня (72 часа) бесплатно — активируется сразу после регистрации, даже без оплаты.
KB1,
            ],
            [
                'sort'  => 2,
                'title' => '💻 Клиент OlcBox: Windows / macOS / Linux (инструкция)',
                'body'  => <<<'KB2'
🌐 **Официальный репозиторий** (скачивать только отсюда!):
<a href="https://github.com/alananisimov/olcbox/releases" target="_blank" rel="noopener noreferrer">https://github.com/alananisimov/olcbox/releases</a>

### Как скачать правильно

- **Windows 10/11** — файл `OlcBox_..._x64_en-US.msi` (установщик) или `.zip`
- **macOS Apple Silicon (M1/M2/M3)** — `OlcBox_..._aarch64.dmg`
- **macOS Intel** — `OlcBox_..._x64.dmg`
- **Linux** — `OlcBox_..._amd64.deb` (Debian/Ubuntu) или AppImage

### Установка

1. Запустите установщик, согласитесь с условиями, Next → Next → Finish.
2. При первом запуске Windows может спросить «Разрешить брандмауэр» — разрешите **для всех сетей**.

### Как добавить вашу подписку

1. Откройте Личный кабинет → «Моя подписка» → 📋 Копировать URI.
2. В OlcBox сверху слева ➕ → Paste from Clipboard.
3. Появится профиль «OlcRTC VPN». Нажмите ▶️ Connect.
4. Верхний индикатор загорелся зелёным = VPN работает!

### Полезные кнопки

- ⚙️ (шестерёнка) рядом с профилем → копировать / экспорт yaml.
- DNS уже настроен на `77.88.8.8` (Яндекс РФ), менять не нужно.
KB2,
            ],
            [
                'sort'  => 3,
                'title' => '🤖 Клиент owenclave: Android (рекомендуется для телефонов)',
                'body'  => <<<'KB3'
🎯 **owenclave** — лучший клиент для Android: обновляется быстрее всех под новые версии OlcRTC, есть обход DPI.

🌐 Скачивать **только отсюда**:
<a href="https://github.com/owenewans/owenclave/releases" target="_blank" rel="noopener noreferrer">https://github.com/owenewans/owenclave/releases</a>

### Что качать

- Файл с названием вида `app-<ВЕРСИЯ>-release.apk` (для 99% современных телефонов — **arm64**).
- Если телефон не устанавливает → Настройки → Безопасность → «Неизвестные источники» (разрешить браузеру / файловому менеджеру).

### Подключение за 30 секунд

1. Откройте браузер на телефоне → Личный кабинет → «Моя подписка».
2. Нажмите «📋 Копировать ключ».
3. Запустите owenclave → снизу справа синяя кнопка ➕.
4. Выберите «Import from clipboard» → появится «Added 1 profile».
5. Нажмите ▶️ «Play» → система спросит «Разрешить VPN-подключение» — ОК.

✅ Когда в статус-баре сверху появился значок 🔑 — всё подключено!

💡 **Совет:** включите в настройках «Always-on VPN» + «Block connections without VPN», чтобы ничего не утекало.
KB3,
            ],
            [
                'sort'  => 4,
                'title' => '🔑 Где взять ключ подключения в кабинете (пошагово)',
                'body'  => <<<'KB4'
✅ **Ответ:** ключ `olcrtc://jitsi?datachannel@https://meet...` — это **ваша личная ссылка для подключения**. У каждого пользователя она своя и уникальная!

### Где найти ключ на сайте (4 клика)

1. Войдите в <a href="/#/login" target="_blank" rel="noopener noreferrer">Личный кабинет</a> (email + пароль, указанные при регистрации).
2. Купите тариф в разделе «Тарифы» — или пользуйтесь бесплатным 3-дневным пробным периодом (сразу после регистрации).
3. Сразу после оплаты откройте в меню «👉 Моя подписка» (англ. Subscription).
4. На странице вы увидите:
   - **🔑 Мой ключ (URI)** — длинная строка вида `olcrtc://jitsi?datachannel@https://meet...`
   - Рядом кнопку **«📋 Скопировать»** (1 нажатие — ключ в буфере обмена).
   - Зелёную подсказку: ✅ Ключ выдан! Вставьте в OlcBox / owenclave → Connect.

### Если ключ не появился сразу (пустая строка)

- Подождите 1–3 минуты (менеджер создаёт инстанс) → обновите страницу.
- Нажмите «🔄 Пересоздать ключ (обновить)» → подождите 30 секунд → F5.
- Проверьте, что тариф оплачен (или 3-дневный пробный период не истёк).
- Если прошло больше 5 минут — напишите в поддержку, мы пересоздадим вручную.

💡 **Проверка:** вставьте скопированный ключ в OlcBox (ПК) или owenclave (Android) и нажмите «Подключить». Загорелся зелёный индикатор — всё работает!
KB4,
            ],
            [
                'sort'  => 5,
                'title' => '❌ НЕ ЗАПУСКАЙТЕ «xboard-node install.sh» (он не для OlcRTC VPN!)',
                'body'  => <<<'KB5'
⛔ **Важно:** команда вида `curl .../xboard-node/dev/install.sh | sudo bash -s -- --mode machine --panel URL --token XXX --machine-id N` — это **сторонний демон** (cedar2025/xboard-node), который OlcRTC VPN **не использует**.

### Зачем она вообще есть в админке (меню «Добавить сервер»)?

Xboard по умолчанию рассчитан на протоколы V2Ray / Xray / Shadowsocks (50+ типов узлов). **OlcRTC** — это отдельный WebRTC-протокол: ему узлы не нужны. Вместо них работает контейнер `olcrtc-manager` внутри `compose.yaml` (поднимается автоматически при установке).

### Что будет, если её запустить?

- Демон `xboard-node` сразу попытается открыть HTTPS 443 на панели → упадёт с `dial tcp <IP>:443: connect: connection refused`.
- Он будет бесконечно перезапускаться, забивать логи и расходовать RAM.
- Лечится автоматически: следующий запуск `install.sh` его находит и удаляет (stop/disable/mask → удаление бинарников и конфигов).

### Разово удалить вручную

```
sudo systemctl stop xboard-node.service 2>/dev/null; sudo systemctl disable xboard-node.service 2>/dev/null; sudo systemctl mask xboard-node.service 2>/dev/null; sudo systemctl daemon-reload 2>/dev/null; sudo rm -f /etc/systemd/system/xboard-node.service /etc/systemd/system/multi-user.target.wants/xboard-node.service /usr/local/bin/xbctl /usr/local/bin/xboard-node; sudo rm -rf /etc/xboard-node /var/lib/xboard-node /var/log/xboard-node 2>/dev/null; (sudo deluser --remove-home xboard-node 2>/dev/null || sudo userdel -r xboard-node 2>/dev/null || true); echo '✅ xboard-node удалён'
```

### Что делать вместо этого?

Ничего! `install.sh` уже поднимает 3 docker-контейнера:

1. **xboard** — PHP/Laravel, Caddy, Octane, Horizon, WS-сервер.
2. **redis** — кеш и сессии.
3. **olcrtc-manager** — Go/Gin, выдаёт персональные URI-ключи `olcrtc://`.

Если эти 3 контейнера UP (`docker compose ps`) — VPN-сервис полностью работает ✅. Никакие «машины» и «узлы» в админке для OlcRTC добавлять не нужно.
KB5,
            ],
            [
                'sort'  => 6,
                'title' => '❓ FAQ: часто задаваемые вопросы',
                'body'  => <<<'KB6'
### ❓ Это бесплатно?

Первые **3 дня (72 часа)** после регистрации — бесплатный пробный период, активируется сразу. Дальше — любой тариф в разделе «Тарифы».

### ❓ Подключился, но сайты не открываются / «нет интернета». Что делать?

1. Закройте клиент и запустите заново → «Подключить».
2. Перезагрузите телефон / ПК (помогает в половине случаев).
3. Попробуйте другую сеть (мобильный интернет вместо Wi-Fi или наоборот).
4. Если не помогло — в Личном кабинете нажмите «🔄 Пересоздать инстанс» и повторите подключение через 2 минуты.

### ❓ Работает ли это в моей стране? ВКонтакте / YouTube открываются?

Да. Подключение идёт через WebRTC (похоже на Zoom / Jitsi), поэтому провайдеры его практически не блокируют.

### ❓ Как оплатить?

Картой (МИР / Visa / MasterCard), СберПей, СБП, ЮMoney через ЮKassa — приходит чек.

### ❓ Как отключить автопродление?

Личный кабинет → Тариф → кнопка «Отменить автопродление» (пока тариф активен, остаток дней можно вернуть в кредит).

### ❓ Сколько устройств одновременно?

Неограниченно с одним URI (но чем больше устройств, тем ниже скорость на каждого).
KB6,
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
            // Триальный тариф обязан ссылаться на РЕАЛЬНЫЙ id нашего «Базового»
            // плана. Жёсткая «1» часто указывает на скрытый/чужой план (или его
            // вовсе нет) — тогда активация бесплатного теста падает и тестовый
            // ключ не выдаётся.
            $basePlanId = 1;
            try {
                $found = Plan::where('name', '🥉 Базовый (30 дней)')->value('id');
                if ($found) $basePlanId = (int) $found;
            } catch (\Throwable $e) {}
            $settings = [
                'olcrtc_only_mode'      => 1,
                'subscribe_disabled'    => 1,
                'server_create_disabled'=> 1,
                'try_out_enable'        => 1,
                'try_out_plan_id'       => $basePlanId,
                'try_out_hour'          => 72,
                // Продукт продаётся за рубли: не оставляем юаневые дефолты
                // апстрима (CNY/¥), иначе в админке и ЛК отображается «元».
                'currency'              => 'RUB',
                'currency_symbol'       => '₽',
            ];
            foreach ($settings as $sKey => $sVal) {
                $row = Setting::where('name', $sKey)->first();
                if (!$row) {
                    $row = new Setting();
                    $row->name = $sKey;
                    $row->created_at = $nowTs;
                }
                $castVal = is_int($sVal) ? (int)$sVal : (string)$sVal;
                $rowVal = is_int($sVal) ? (int)($row->value ?? 0) : (string)($row->value ?? '');
                if ($rowVal !== $castVal) {
                    $row->value = (string)$sVal;
                    $row->updated_at = $nowTs;
                    $row->save();
                }
            }
            $this->info(sprintf(
                '  · v2_settings: olcrtc_only_mode=1, subscribe_disabled=1, server_create_disabled=1, try_out_plan=id%d (Базовый), trial=%dh enabled ✅',
                (int) ($settings['try_out_plan_id'] ?? 0),
                (int) ($settings['try_out_hour'] ?? 0)
            ));
        }

        // Hide non-OlcRTC plans (anything that isn't our 3 seeded titles)
        if (Schema::hasTable('v2_plan')) {
            $targetPlanNames = [
                '🥉 Базовый (30 дней)',
                '🥈 Профи (90 дней) — выгода 16%',
                '🥇 Максимум (1 год) — выгода 37%',
            ];
            try {
                $hiddenCount = Plan::whereNotIn('name', $targetPlanNames)
                    ->where('show', '=', 1)
                    ->update(['show' => 0, 'updated_at' => $nowTs]);
            } catch (\Throwable $e) {
                $hiddenCount = 0;
                $this->warn('  · ⚠️ hide non-OlcRTC plans skipped: ' . $e->getMessage());
            }
            // Force-overwrite tags/capacity_limit/device_limit for our 3 plans
            // Schema-guarded: only include columns that actually exist in v2_plan.
            $planUpdate = [];
            if (Schema::hasColumn('v2_plan', 'tags')) $planUpdate['tags'] = ['OlcRTC', 'VPN', 'WebRTC'];
            if (Schema::hasColumn('v2_plan', 'capacity_limit')) $planUpdate['capacity_limit'] = 999999;
            if (Schema::hasColumn('v2_plan', 'device_limit')) $planUpdate['device_limit'] = 0;
            if (Schema::hasColumn('v2_plan', 'transfer_enable')) $planUpdate['transfer_enable'] = 0;
            if (Schema::hasColumn('v2_plan', 'reset_traffic_method')) $planUpdate['reset_traffic_method'] = Plan::RESET_TRAFFIC_NEVER;
            if (Schema::hasColumn('v2_plan', 'renew')) $planUpdate['renew'] = 1;
            if (Schema::hasColumn('v2_plan', 'sell')) $planUpdate['sell'] = 1;
            if (count($planUpdate) > 0) {
                $planUpdate['updated_at'] = $nowTs;
                try {
                    Plan::whereIn('name', $targetPlanNames)->update($planUpdate);
                } catch (\Throwable $e) {
                    $this->warn('  · ⚠️ plan normalize skipped: ' . $e->getMessage());
                }
            }
            if ($hiddenCount > 0) {
                $this->info('  · Тарифы: скрыто ' . $hiddenCount . ' не-OlcRTC планов (show=0) ✅');
            } else {
                $this->info('  · Тарифы: 3 OlcRTC-плана показываются, лишних не обнаружено ✅');
            }
        }

        // Disable non-(YooKassa/YooMoney) payment methods (column in v2_payment is `enable`, not `enabled`)
        if (Schema::hasTable('v2_payment')) {
            $disabledCount = Payment::whereNotIn('payment', ['yookassa', 'yoomoney'])
                ->where('enable', '=', 1)
                ->update(['enable' => 0, 'updated_at' => $nowTs]);
            if ($disabledCount > 0) {
                $this->info('  · Оплаты: отключено ' . $disabledCount . ' лишних шлюзов (не ЮKassa/ЮMoney) ✅');
            } else {
                $this->info('  · Оплаты: лишних включённых шлюзов не найдено ✅');
            }
            // Extra sanity: ЮKassa НЕ должна оставаться включённой с тестовым
            // (или пустым) secret_key — боевой API отвечает 401, и ЛК показывает
            // «Похоже, это техническая ошибка» при выборе способа оплаты.
            $yooRow = Payment::where('payment', 'yookassa')->first();
            if ($yooRow && (bool) $yooRow->enable && !$this->_yookassaIsLive($yooRow->config)) {
                $yooRow->enable = 0;
                $yooRow->updated_at = $nowTs;
                $yooRow->save();
                $this->warn('  · Платёжка ЮKassa: тестовый/пустой secret_key → принудительно выключена ⚠️');
            }
        }

        // Disable ALL plugins in v2_plugins EXCEPT olc_rtc (OlcRTC Integration).
        // PluginManager::installDefaultPlugins() has `plugins-core` forceEnable=true
        // which re-enables AlipayF2F/BTCPay/Coinbase/... on every container boot, so
        // we need to disable them again idempotently (both during xboard:install AND
        // any subsequent xboard:update / plugin self-heal cycles).
        if (Schema::hasTable('v2_plugins') && Schema::hasColumn('v2_plugins', 'is_enabled')) {
            try {
                $disabledPluginCount = \App\Models\Plugin::where('code', '<>', 'olc_rtc')
                    ->where('is_enabled', '=', 1)
                    ->update(['is_enabled' => 0, 'updated_at' => $nowTs]);
                if ($disabledPluginCount > 0) {
                    $this->info('  · Плагины: отключено ' . $disabledPluginCount . ' лишних (Alipay/BTCPay/Coinbase/...) ✅');
                } else {
                    $this->info('  · Плагины: только OlcRTC активен ✅');
                }
            } catch (\Throwable $e) {
                $this->warn('  · ⚠️  Плагины: не удалось отключить лишние: ' . $e->getMessage());
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

        // Phase 6.8 Theme Xboard custom_html → УДАЛЕНИЕ legacy-дубликата виджета ключа.
        // Ранее установщик вшивал в v2_settings.theme_Xboard.custom_html отдельный
        // СВЕТЛЫЙ HTML-блок (<style>/<div id="olcrtc-key-widget">/<script>), который
        // рендерился в конце <body> и перекрывал тёмный <style id="olcrtc-widget-style">
        // из dashboard.blade.php — из-за этого карточка ключа становилась белой, а ссылки
        // сливались с фоном. Теперь виджет живёт ТОЛЬКО в dashboard.blade.php, поэтому
        // legacy-копию нужно вырезать из custom_html (в т.ч. на уже развёрнутых системах).
        try {
            $themeCode = 'Xboard'; // mirrors routes/web.php default theme
            $settingName = 'theme_' . $themeCode;
            $themeCfg = admin_setting($settingName, null);
            if ($themeCfg === null || !is_array($themeCfg)) {
                try {
                    $ts = new \App\Services\ThemeService();
                    $ts->updateConfig($themeCode, []);
                } catch (\Throwable $e) { /* ignore if no tables yet */ }
                $themeCfg = admin_setting($settingName, null);
            }
            if (is_array($themeCfg)) {
                // Ранее сюда вшивалась ОТДЕЛЬНАЯ светлая копия виджета ключа
                // (<style>+<div id="olcrtc-key-widget">+<script>). Она рендерилась
                // в конце <body> ПОСЛЕ тёмного <style id="olcrtc-widget-style"> из
                // dashboard.blade.php и перекрывала его: .okw-body становился белым,
                // а бледно-зелёные ссылки сливались с фоном. Единый виджет теперь
                // живёт только в dashboard.blade.php, поэтому legacy-копию нужно
                // ВЫРЕЗАТЬ из custom_html (это же лечит уже развёрнутые системы).
                $existingCustom = $themeCfg['custom_html'] ?? '';
                if (is_string($existingCustom) && str_contains($existingCustom, 'olcrtc-key-widget')) {
                    $cleaned = preg_replace(
                        '~<style>\s*#olcrtc-key-widget\{position:relative;.*?</script>~s',
                        '',
                        $existingCustom
                    );
                    if (is_string($cleaned) && $cleaned !== $existingCustom) {
                        $themeCfg['custom_html'] = trim($cleaned);
                        admin_setting([$settingName => $themeCfg]);
                        $this->info('  · Тема: из custom_html удалён устаревший светлый дубликат виджета ✅');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  · ⚠️  Тема custom_html widget не вшит (не критично): ' . $e->getMessage());
        }

        // Phase 6.9 Matrix-Green Landing Theme — override default SPA light palette with hacker-friendly
        // green-on-black, set site_name/description, and inject a tiny <style> layer into theme's
        // custom_html so that even when index.html is re-copied from scratch, Umi SPA body + login
        // card remain black background with green accents (matrix-friendly palette).
        try {
            $themeCode = 'Xboard';
            $settingName = 'theme_' . $themeCode;
            $themeCfg = admin_setting($settingName, null);
            if (!is_array($themeCfg)) {
                try {
                    $ts = new \App\Services\ThemeService();
                    $ts->updateConfig($themeCode, []);
                } catch (\Throwable $e) { /* ignore */ }
                $themeCfg = admin_setting($settingName, null) ?: [];
            }
            if (is_array($themeCfg)) {
                $themeCfg['frontend_theme_color'] = 'dark_matrix';
                $themeCfg['theme_mode'] = 'dark';
                $themeCfg['frontend_theme_color_login_bg'] = '#000';
                $themeCfg['frontend_theme_color_login_btn'] = '#00ff9c';
                $themeCfg['frontend_theme_color_login_text'] = '#00ff9c';
                $themeCfg['frontend_theme_color_login_card_bg'] = '#040c08cc';
                $themeCfg['frontend_theme_color_login_card_border'] = '#00ff9c55';
                $landingOverride =
'<style id="mvpn-landing-override-seed">'."\n".
'  html,body{background:#000 !important;color:#c9ffd9;}'."\n".
'  .ant-layout,.layout{background:#000 !important;}'."\n".
'  .ant-layout-content{background:#000 !important;}'."\n".
'  .ant-card,.ant-modal-content,.ant-form,.login-wrap,.register-wrap{background:#040c08f0 !important;color:#c9ffd9;border:1px solid #00ff9c55 !important;box-shadow:0 20px 70px rgba(0,255,156,.18) !important;border-radius:12px !important;}'."\n".
'  .ant-btn-primary,.ant-btn-default[type=submit],button.btn-primary{background:linear-gradient(135deg,#00ff9c,#00e5ff) !important;border:0 !important;color:#00110a !important;text-shadow:none !important;box-shadow:0 8px 22px rgba(0,255,156,.35) !important;}'."\n".
'  .ant-input,.ant-input-password,.ant-select-selector{background:#00120a !important;color:#c9ffd9 !important;border-color:#00ff9c55 !important;}'."\n".
'  .ant-input::placeholder,.ant-form-item-label>label{color:#74c99a !important;}'."\n".
'  h1,h2,h3,.ant-typography{color:#fff !important;}'."\n".
'</style>'."\n";
                $existingCustom = is_string($themeCfg['custom_html'] ?? null) ? $themeCfg['custom_html'] : '';
                if (strpos($existingCustom, 'id="mvpn-landing-override-seed"') === false) {
                    $themeCfg['custom_html'] = $landingOverride . "\n" . $existingCustom;
                }
                admin_setting([$settingName => $themeCfg]);

                // Set site-wide identity (frontend SPA displays these in login header if used;
                // also app_name is used as <title> fallback on landing via routes/web.php admin_setting('app_name','Xboard'))
                try {
                    DB::table('v2_settings')->upsert(
                        [
                            ['name' => 'app_name', 'value' => 'Mobi VPN'],
                            ['name' => 'app_description', 'value' => 'Защищённый VPN по протоколу OlcRTC (Jitsi WebRTC datachannel) — купил подписку → сразу получил ключ olcrtc://jitsi?datachannel@… в ЛК.'],
                            ['name' => 'frontend_theme', 'value' => 'Xboard'],
                            ['name' => 'site_name', 'value' => 'Mobi VPN · OlcRTC Secure Panel'],
                            ['name' => 'site_description', 'value' => 'Защищённый VPN по протоколу OlcRTC (jitsi datachannel) — купил подписку → сразу получил зелёную карточку с кнопкой «Скопировать ключ OlcRTC (URI)». Формат ключа: olcrtc://jitsi?datachannel@https://host/room#HASH$olc.'],
                            ['name' => 'site_logo', 'value' => ''],
                            ['name' => 'site_url', 'value' => function_exists('config') ? (config('app.url') ?: '') : ''],
                        ],
                        ['name'],
                        ['value']
                    );
                } catch (\Throwable $e) { /* ignore if columns mismatch */ }

                $this->info('  · Тема: матрично-зелёная палитра + site_name Mobi VPN + login-bg #000 → применены (AUTO-SEED Phase6.9) ✅');
            }
        } catch (\Throwable $e) {
            $this->warn('  · ⚠️  Тема матрицы не вшита (не критично): ' . $e->getMessage());
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
