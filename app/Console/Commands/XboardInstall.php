<?php

namespace App\Console\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
                $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("✅ Панель уже установлена. URL админки: http(s)://ваш-сайт/{$securePath} — не забудьте сменить пароль в разделе «Мой профиль».");
                $this->warn('Чтобы ПЕРЕУСТАНОВИТЬ панель с нуля — ОЧИСТИТЕ содержимое файла .env в корне проекта (НО НЕ УДАЛЯЙТЕ сам .env при Docker-развёртывании).');
                $this->warn('Быстрая команда очистки .env:');
                note('rm .env && touch .env');

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
