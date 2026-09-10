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
                $this->info("访问 http(s)://你的站点/{$securePath} 进入管理面板，你可以在用户中心修改你的密码。");
                $this->warn("如需重新安装请清空目录下 .env 文件的内容（Docker安装方式不可以删除此文件）");
                $this->warn("快捷清空.env命令：");
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
                $this->error('😔：安装失败，Docker环境下安装请保留空的 .env 文件');
                return;
            }
            // 选择数据库类型
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
                    label: '请选择数据库类型',
                    options: [
                        'sqlite' => 'SQLite (无需额外安装)',
                        'mysql' => 'MySQL',
                        'postgresql' => 'PostgreSQL'
                    ],
                    default: 'sqlite'
                );
            }

            // 使用 match 表达式配置数据库
            $envConfig = match ($dbType) {
                'sqlite' => $this->configureSqlite(),
                'mysql' => $this->configureMysql(),
                'postgresql' => $this->configurePostgresql(),
                default => throw new \InvalidArgumentException("不支持的数据库类型: {$dbType}")
            };

            if (is_null($envConfig)) {
                return; // 用户选择退出安装
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

                // 判断是否为Docker环境
                $useBuiltinRedis = $isDocker && ($enableRedis || confirm(label: '是否启用Docker内置的Redis', default: true, yes: '启用', no: '不启用'));
                if ($useBuiltinRedis) {
                    if ($redisHostEnv && is_string($redisHostEnv) && !str_starts_with($redisHostEnv, '/')) {
                        // User already set TCP hostname via env → prefer that over socket
                        $envConfig['REDIS_HOST'] = $redisHostEnv;
                        $envConfig['REDIS_PORT'] = $redisPortEnv !== false ? (int) $redisPortEnv : 6379;
                        $envConfig['REDIS_PASSWORD'] = $redisPasswordEnv !== false ? (string) $redisPasswordEnv : '';
                    } else {
                        $envConfig['REDIS_HOST'] = '/data/redis.sock';
                        $envConfig['REDIS_PORT'] = 0;
                        $envConfig['REDIS_PASSWORD'] = null;
                    }
                    $isReidsValid = true;
                    break;
                }
                $defaultRedisHost = $isDocker && $enableRedis ? 'redis' : '127.0.0.1';
                $envConfig['REDIS_HOST'] = text(label: '请输入Redis地址', default: $defaultRedisHost, required: true);
                $envConfig['REDIS_PORT'] = text(label: '请输入Redis端口', default: '6379', required: true);
                $envConfig['REDIS_PASSWORD'] = text(label: '请输入redis密码(默认: null)', default: '');
                $redisConfig = [
                    'client' => 'phpredis',
                    'default' => [
                        'host' => $envConfig['REDIS_HOST'],
                        'password' => $envConfig['REDIS_PASSWORD'],
                        'port' => $envConfig['REDIS_PORT'],
                        'database' => 0,
                    ],
                ];
                try {
                    $redis = new \Illuminate\Redis\RedisManager(app(), 'phpredis', $redisConfig);
                    $redis->ping();
                    $isReidsValid = true;
                } catch (\Exception $e) {
                    // 连接失败，输出错误消息
                    $this->error("redis连接失败：" . $e->getMessage());
                    $this->info("请重新输入REDIS配置");
                    $enableRedis = false;
                    sleep(1);
                }
            }

            if (!copy(base_path() . '/.env.example', base_path() . '/.env')) {
                abort(500, '复制环境文件失败，请检查目录权限');
            }
            ;
            if ($autoInstall) {
                $email = !empty($adminAccount) ? $adminAccount : 'admin@example.com';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("ADMIN_ACCOUNT={$email} — неверный формат email, используем admin@example.com");
                    $email = 'admin@example.com';
                }
            } else {
                $email = !empty($adminAccount) ? $adminAccount : text(
                    label: '请输入管理员账号',
                    default: 'admin@demo.com',
                    required: true,
                    validate: fn(string $email): ?string => match (true) {
                        !filter_var($email, FILTER_VALIDATE_EMAIL) => '请输入有效的邮箱地址.',
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
            $this->info('正在导入数据库请稍等...');
            Artisan::call("migrate", ['--force' => true]);
            $this->info(Artisan::output());
            $this->info('数据库导入完成');
            $this->info('开始注册管理员账号');
            if (!self::registerAdmin($email, $password)) {
                abort(500, '管理员账号注册失败，请重试');
            }
            $this->info('正在安装默认插件...');
            // -----------------------------------------------------------------
            // Re-run migrations right before installing default plugins because:
            //   * many users deploy the .env without ever running `migrate`,
            //   * and the plugin system relies on the v2_plugins table which
            //     lives in 2025_01_18_140511_create_plugins_table.php.
            // Running migrate twice in a row is a no-op for already-applied
            // batches (Laravel keeps migrations table), so this is safe even if
            // the previous migrate call above already ran.
            // -----------------------------------------------------------------
            Artisan::call('migrate', ['--force' => true]);
            $migOut = Artisan::output();
            if (trim($migOut) !== '') {
                $this->line($migOut);
            }
            // Ensure the plugins table actually exists before we touch plugins.
            if (!\Illuminate\Support\Facades\Schema::hasTable('v2_plugins')) {
                $this->error('致命错误: v2_plugins 表仍不存在 — 插件系统将不可用。请手动运行: php artisan migrate');
            } else {
                PluginManager::installDefaultPlugins();
                $this->info('默认插件安装完成');
            }

            // -----------------------------------------------------------------
            // Materialise the admin React SPA.
            // Docker deployments rely on the entrypoint (and Dockerfile) to
            // clone xboard-admin-dist into public/assets/admin.  For non-Docker
            // (bare-metal / composer-installed) setups we also attempt a git
            // clone here so the admin panel just works out-of-the-box.
            // -----------------------------------------------------------------
            $adminDir  = public_path('assets/admin');
            $adminRepo = env('ADMIN_DIST_REPO', 'https://github.com/cedar2025/xboard-admin-dist.git');
            $manifest  = $adminDir . '/manifest.json';
            if (!is_dir($adminDir) || !is_file($manifest) || !filesize($manifest)) {
                $this->info('正在获取管理面板前端 (xboard-admin-dist) ...');
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
                    $this->info('管理面板前端已安装: ' . count(File::allFiles($adminDir)) . ' 个文件');
                } else {
                    $this->warn("自动安装管理面板前端失败 (git exit {$code}).");
                    $this->warn('请手动执行 (在项目根目录):');
                    note("git clone --depth=1 {$adminRepo} public/assets/admin");
                }
                @File::deleteDirectory($tmp);
            } else {
                $this->info('管理面板前端已就绪: ' . count(File::allFiles($adminDir)) . ' 个文件');
            }

            $this->info('🎉：一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("访问 http(s)://你的站点/{$defaultSecurePath} 进入管理面板，你可以在用户中心修改你的密码。");
            $this->warn("如果部署在 Docker 环境下，容器入口脚本也会自动补充管理面板前端。");
            $envConfig['INSTALLED'] = true;
            $this->saveToEnv($envConfig);
            foreach (array_keys($installDriverOverrides) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            Artisan::call('config:clear');
        } catch (\Exception $e) {
            $this->error($e);
        }
    }

    public static function registerAdmin($email, $password)
    {
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, '管理员密码长度最小为8位字符');
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
     * 配置 SQLite 数据库
     *
     * @return array|null
     */
    private function configureSqlite(): ?array
    {
        $sqliteFile = '.docker/.data/xboard.sqlite';
        if (!file_exists(base_path($sqliteFile))) {
            // 创建空文件
            if (!is_dir(dirname(base_path($sqliteFile)))) {
                @mkdir(dirname(base_path($sqliteFile)), 0775, true);
            }
            if (!touch(base_path($sqliteFile))) {
                $this->info("sqlite创建成功: $sqliteFile");
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
                    if (confirm(label: '检测到数据库中已经存在数据，是否要清空数据库以便安装新的数据？', default: false, yes: '清空', no: '退出安装')) {
                        $doWipe = true;
                    } else {
                        return null;
                    }
                }
                if ($doWipe) {
                    $this->info('正在清空数据库请稍等');
                    $this->call('db:wipe', ['--force' => true]);
                    $this->info('数据库清空完成');
                }
            }
        } catch (\Exception $e) {
            $this->error("SQLite数据库连接失败：" . $e->getMessage());
            return null;
        }

        return $envConfig;
    }

    /**
     * 配置 MySQL 数据库
     *
     * @return array
     */
    private function configureMysql(): array
    {
        while (true) {
            $envConfig = [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => text(label: "请输入MySQL数据库地址", default: '127.0.0.1', required: true),
                'DB_PORT' => text(label: '请输入MySQL数据库端口', default: '3306', required: true),
                'DB_DATABASE' => text(label: '请输入MySQL数据库名', default: 'xboard', required: true),
                'DB_USERNAME' => text(label: '请输入MySQL数据库用户名', default: 'root', required: true),
                'DB_PASSWORD' => text(label: '请输入MySQL数据库密码', required: false),
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
                    if (confirm(label: '检测到数据库中已经存在数据，是否要清空数据库以便安装新的数据？', default: false, yes: '清空', no: '不清空')) {
                        $this->info('正在清空数据库请稍等');
                        $this->call('db:wipe', ['--force' => true]);
                        $this->info('数据库清空完成');
                        return $envConfig;
                    } else {
                        continue; // 重新输入配置
                    }
                }

                return $envConfig;
            } catch (\Exception $e) {
                $this->error("MySQL数据库连接失败：" . $e->getMessage());
                $this->info("请重新输入MySQL数据库配置");
            }
        }
    }

    /**
     * 配置 PostgreSQL 数据库
     *
     * @return array
     */
    private function configurePostgresql(): array
    {
        while (true) {
            $envConfig = [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => text(label: "请输入PostgreSQL数据库地址", default: '127.0.0.1', required: true),
                'DB_PORT' => text(label: '请输入PostgreSQL数据库端口', default: '5432', required: true),
                'DB_DATABASE' => text(label: '请输入PostgreSQL数据库名', default: 'xboard', required: true),
                'DB_USERNAME' => text(label: '请输入PostgreSQL数据库用户名', default: 'postgres', required: true),
                'DB_PASSWORD' => text(label: '请输入PostgreSQL数据库密码', required: false),
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

                // 检查PostgreSQL数据库是否有表
                $tables = DB::connection('pgsql')->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                if (!blank($tables)) {
                    if (confirm(label: '检测到数据库中已经存在数据，是否要清空数据库以便安装新的数据？', default: false, yes: '清空', no: '不清空')) {
                        $this->info('正在清空数据库请稍等');
                        $this->call('db:wipe', ['--force' => true]);
                        $this->info('数据库清空完成');
                        return $envConfig;
                    } else {
                        continue; // 重新输入配置
                    }
                }

                return $envConfig;
            } catch (\Exception $e) {
                $this->error("PostgreSQL数据库连接失败：" . $e->getMessage());
                $this->info("请重新输入PostgreSQL数据库配置");
            }
        }
    }
}
