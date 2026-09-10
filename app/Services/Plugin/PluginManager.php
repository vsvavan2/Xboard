<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManager
{
    protected string $pluginPath;
    protected string $corePluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    public function __construct()
    {
        $this->pluginPath = base_path('plugins');
        $this->corePluginPath = base_path('plugins-core');
    }

    /**
     * 获取插件的命名空间
     *
     * IMPORTANT: we MUST match the EXACT case of the actual folder name because:
     *   - composer.json declares PSR-4:   "Plugin\\": "plugins/"
     *   - on Linux (ext4) + class_exists() / require_once with PSR-4 autoloader
     *     the namespace segment MUST equal the folder name byte-for-byte,
     *     otherwise you get "Plugin class not found: Plugin\OlcRtc\Plugin"
     *     even though the real file is plugins/OlcRTC/Plugin.php.
     * Therefore we always resolve the real on-disk directory first and then
     * basename() it — instead of trusting Str::studly() which is lossy for
     * acronyms / multi-case tokens (olc_rtc => "OlcRtc" vs "OlcRTC").
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        $resolvedDir = $this->resolvePluginPath($pluginCode);
        $folderName = $resolvedDir !== null
            ? basename($resolvedDir)
            : Str::studly($pluginCode);
        return 'Plugin\\' . $folderName;
    }

    public function resolvePluginPath(string $pluginCode): ?string
    {
        // Build multiple candidate folder names because:
        //   - plugin codes use snake_case (e.g. "olc_rtc"),
        //   - real folders can be StudlyCaps with acronyms (e.g. "OlcRTC", not "OlcRtc"),
        //   - on Linux filesystems the lookup is case-sensitive so we must match exactly.
        $studly = Str::studly($pluginCode);
        $parts  = explode('_', trim($pluginCode, '_'));

        // Build extra variants for acronym folders. Given snake code "olc_rtc" we want:
        //   OlcRTC, OLCRtc, oLCRTC, OlcRTc, OlcrtcUpper, etc — every part has (ucfirst, UPPER)
        $partVariants = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $partVariants[] = [
                ucfirst($part),
                strtoupper($part),
                lcfirst($part),
                $part,
            ];
        }
        $combos = [''];
        foreach ($partVariants as $variants) {
            $next = [];
            foreach ($combos as $prefix) {
                foreach ($variants as $v) {
                    $next[] = $prefix . $v;
                }
            }
            $combos = $next;
        }

        $candidates = array_values(array_unique(array_merge(
            [$studly],
            [ucfirst($pluginCode)],
            [str_replace(' ', '', ucwords(str_replace('_', ' ', $pluginCode)))],
            [$this->mbUcwordsAll($studly)],
            [strtoupper($studly)],
            // Guarantee acronym matches (OlcRTC / OLCRTC / olcRTC ...) — full Cartesian
            $combos
        )));

        foreach ([$this->corePluginPath, $this->pluginPath] as $baseDir) {
            if (!File::isDirectory($baseDir)) {
                continue;
            }
            // 1) exact candidate match (fast path) — a folder must contain config.json
            //    to be considered a valid plugin directory (avoids false matches).
            foreach ($candidates as $name) {
                $p = $baseDir . '/' . $name;
                if (File::isDirectory($p) && File::exists($p . '/config.json')) {
                    return $p;
                }
            }
            // 2) case-insensitive scan as last resort (Linux docker images usually
            //    run on ext4 which is strictly case-sensitive).
            foreach (scandir($baseDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($baseDir . '/' . $entry)) {
                    continue;
                }
                $cmpEntry   = str_replace(['_', '-', ' '], '', strtolower($entry));
                $cmpCode    = str_replace(['_', '-', ' '], '', strtolower($pluginCode));
                $cmpStudly  = str_replace(['_', '-', ' '], '', strtolower($studly));
                if ($cmpEntry === $cmpCode
                    || $cmpEntry === $cmpStudly
                    || strcasecmp($entry, $studly) === 0
                    || strcasecmp($entry, str_replace('_', '', $pluginCode)) === 0) {
                    if (File::exists($baseDir . '/' . $entry . '/config.json')) {
                        return $baseDir . '/' . $entry;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Uppercase every letter that follows an underscore/digit (aggressive studly),
     * producing extra folder-name candidates such as "OlcRTC" from "olc_rtc".
     */
    private function mbUcwordsAll(string $s): string
    {
        return (string) preg_replace_callback('/(?:^|_|[0-9])([a-z])/', function ($m) {
            return strtoupper($m[0]);
        }, $s);
    }

    public function getPluginPath(string $pluginCode): string
    {
        return $this->resolvePluginPath($pluginCode)
            ?? $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function getUserPluginPath(string $pluginCode): string
    {
        $resolved = $this->resolvePluginPath($pluginCode);
        if ($resolved !== null && str_starts_with($resolved, rtrim($this->pluginPath, '/') . '/')) {
            return $resolved;
        }
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function isCorePlugin(string $pluginCode): bool
    {
        return $this->resolvePluginPath($pluginCode) !== null
            && str_starts_with($this->resolvePluginPath($pluginCode), rtrim($this->corePluginPath, '/') . '/');
    }

    public function getPluginPaths(): array
    {
        return [$this->corePluginPath, $this->pluginPath];
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $resolvedDir = $this->resolvePluginPath($pluginCode);
        $pluginPath = $this->getPluginPath($pluginCode);
        $pluginNamespace = $this->getPluginNamespace($pluginCode);
        $pluginClass = $pluginNamespace . '\\Plugin';
        $pluginFile = $pluginPath . '/Plugin.php';

        $classExistsBefore = class_exists($pluginClass, false);
        if (!$classExistsBefore) {
            $fileExists = File::exists($pluginFile);
            if (!$fileExists) {
                $diag = $this->buildPluginDiagnostics($pluginCode, $resolvedDir, $pluginPath, $pluginNamespace, $pluginFile);
                Log::warning("[PluginManager] loadPlugin({$pluginCode}) Plugin.php NOT FOUND — " . json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return null;
            }
            $prevError = error_get_last();
            try {
                require_once $pluginFile;
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    "[PluginManager] loadPlugin(%s) require_once threw: %s (in %s:%d)",
                    $pluginCode,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                return null;
            }
            $afterError = error_get_last();
            if ($afterError !== $prevError && $afterError !== null && (int)($afterError['type'] ?? 0) <= 1) {
                Log::error(sprintf(
                    "[PluginManager] loadPlugin(%s) require_once raised PHP error: type=%s msg=%s (in %s:%d)",
                    $pluginCode,
                    $afterError['type'] ?? '?',
                    $afterError['message'] ?? '?',
                    $afterError['file'] ?? '?',
                    $afterError['line'] ?? 0
                ));
            }
        }

        $classExistsAfter = class_exists($pluginClass, false);
        if (!$classExistsAfter) {
            $diag = $this->buildPluginDiagnostics($pluginCode, $resolvedDir, $pluginPath, $pluginNamespace, $pluginFile);
            $diag['class_exists_before'] = $classExistsBefore;
            $diag['class_exists_after_require_once'] = $classExistsAfter;
            Log::error("[PluginManager] loadPlugin({$pluginCode}) class STILL NOT FOUND after require_once — " . json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return null;
        }

        try {
            $plugin = new $pluginClass($pluginCode);
        } catch (\Throwable $e) {
            Log::error(sprintf(
                "[PluginManager] loadPlugin(%s) instantiation of %s failed: %s (in %s:%d)",
                $pluginCode,
                $pluginClass,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return null;
        }
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * Build a diagnostic payload for "Plugin not found" style errors so the
     * admin-UI toast / CLI output immediately shows WHY the plugin loader
     * failed (case mismatch, folder missing inside container, wrong CWD, etc).
     */
    protected function buildPluginDiagnostics(
        string $pluginCode,
        ?string $resolvedDir,
        string $fallbackPluginPath,
        string $expectedNamespace,
        string $expectedPluginFile
    ): array {
        $candidates = [];
        $studly = Str::studly($pluginCode);
        $parts  = explode('_', trim($pluginCode, '_'));
        $partVariants = [];
        foreach ($parts as $part) {
            if ($part === '') { continue; }
            $partVariants[] = [ucfirst($part), strtoupper($part), lcfirst($part), $part];
        }
        $combos = [''];
        foreach ($partVariants as $variants) {
            $next = [];
            foreach ($combos as $prefix) {
                foreach ($variants as $v) { $next[] = $prefix . $v; }
            }
            $combos = $next;
        }
        foreach ([$this->corePluginPath, $this->pluginPath] as $baseDir) {
            foreach (array_unique($combos) as $c) {
                $candidates[] = $baseDir . '/' . $c . '/config.json';
                $candidates[] = $baseDir . '/' . $c . '/Plugin.php';
            }
        }
        $candidatesMatched = [];
        foreach (array_unique($candidates) as $cand) {
            if (File::exists($cand)) { $candidatesMatched[] = $cand; }
        }

        $listDir = function (?string $dir): array {
            if ($dir === null || !File::isDirectory($dir)) { return ['<MISSING DIR: ' . ($dir ?? 'null') . '>']; }
            try {
                $entries = scandir($dir);
                if ($entries === false) { return ['<scandir FAILED>']; }
                $result = [];
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') { continue; }
                    $full = $dir . '/' . $e;
                    $suffix = File::isDirectory($full) ? '/' : (File::exists($full) ? '' : '?');
                    $result[] = $e . $suffix;
                }
                return $result;
            } catch (\Throwable $e) {
                return ['<scandir EXCEPTION: ' . $e->getMessage() . '>'];
            }
        };

        return [
            'plugin_code'            => $pluginCode,
            'studly'                 => $studly,
            'resolvePluginPath'      => $resolvedDir,
            'getPluginPath_fallback' => $fallbackPluginPath,
            'plugin_namespace'       => $expectedNamespace,
            'plugin_class_fqcn'      => $expectedNamespace . '\\Plugin',
            'plugin_file_expected'   => $expectedPluginFile,
            'plugin_file_FileExists' => File::exists($expectedPluginFile),
            'config_file_exists_here'=> File::exists(($resolvedDir ?? $fallbackPluginPath) . '/config.json'),
            'base_path()'            => base_path(),
            'getcwd()'               => @getcwd() ?: '<false>',
            'doc_root'               => $_SERVER['DOCUMENT_ROOT'] ?? '<n/a>',
            'candidates_matched'     => $candidatesMatched,
            'scan_plugins_dir'       => $listDir($this->pluginPath),
            'scan_plugins-core_dir'  => $listDir($this->corePluginPath),
        ];
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';

        if (class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (File::exists($routesPath)) {
            $webRouteFile = $routesPath . '/web.php';
            $apiRouteFile = $routesPath . '/api.php';
            if (File::exists($webRouteFile)) {
                Route::middleware('web')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($webRouteFile) {
                        require $webRouteFile;
                    });
            }
            if (File::exists($apiRouteFile)) {
                Route::middleware('api')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($apiRouteFile) {
                        require $apiRouteFile;
                    });
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            View::addNamespace(Str::studly($pluginCode), $viewsPath);
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // 检查依赖
        if (!$this->checkDependencies($config['require'] ?? [])) {
            throw new \Exception('Dependencies not satisfied');
        }

        // 运行数据库迁移
        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            // 提取配置默认值
            $defaultValues = $this->extractDefaultConfig($config);

            // 创建插件实例
            $plugin = $this->loadPlugin($pluginCode);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 获取 Migrator 实例并确保迁移仓库存在
     */
    protected function getMigrator(): \Illuminate\Database\Migrations\Migrator
    {
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->run([$migrationsPath]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->rollback([$migrationsPath]);
        }
    }

    /**
     * 发布插件资源
     */
    protected function publishAssets(string $pluginCode): void
    {
        $assetsPath = $this->getPluginPath($pluginCode) . '/resources/assets';
        if (File::exists($assetsPath)) {
            $publishPath = public_path('plugins/' . $pluginCode);
            File::ensureDirectoryExists($publishPath);
            File::copyDirectory($assetsPath, $publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);

        if (!$plugin) {
            $resolvedDir = $this->resolvePluginPath($pluginCode);
            $pluginPath = $this->getPluginPath($pluginCode);
            $pluginNamespace = $this->getPluginNamespace($pluginCode);
            $pluginFile = $pluginPath . '/Plugin.php';
            $diag = $this->buildPluginDiagnostics($pluginCode, $resolvedDir, $pluginPath, $pluginNamespace, $pluginFile);
            Log::error("[PluginManager] enable({$pluginCode}) loadPlugin returned null — " . json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $shortMsg = sprintf(
                "Plugin not found: %s\n" .
                "  code=%s namespace=%s\n" .
                "  expectedPluginFile=%s (exists=%s)\n" .
                "  resolvedDir=%s (configExists=%s)\n" .
                "  base_path=%s  cwd=%s\n" .
                "  scan(plugins)=%s\n" .
                "  scan(plugins-core)=%s\n" .
                "  matchedCandidates=%s",
                $pluginCode,
                $diag['plugin_code'],
                $diag['plugin_namespace'],
                $diag['plugin_file_expected'],
                $diag['plugin_file_FileExists'] ? 'YES' : 'NO',
                $diag['resolvePluginPath'] ?? '<NULL>',
                $diag['config_file_exists_here'] ? 'YES' : 'NO',
                $diag['base_path()'],
                $diag['getcwd()'],
                implode(', ', $diag['scan_plugins_dir']),
                implode(', ', $diag['scan_plugins-core_dir']),
                $diag['candidates_matched'] ? implode("\n                   - ", $diag['candidates_matched']) : '<none>'
            );
            throw new \Exception($shortMsg);
        }

        // 获取插件配置
        $dbPlugin = Plugin::query()
            ->where('code', $pluginCode)
            ->first();

        if ($dbPlugin && !empty($dbPlugin->config)) {
            $values = json_decode($dbPlugin->config, true) ?: [];
            $values = $this->castConfigValuesByType($pluginCode, $values);
            $plugin->setConfig($values);
        }

        // 注册服务提供者
        $this->registerServiceProvider($pluginCode);

        // 加载路由
        $this->loadRoutes($pluginCode);

        // 加载视图
        $this->loadViews($pluginCode);

        // 更新数据库状态
        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => true,
                'updated_at' => now(),
            ]);
        // 初始化插件
        $plugin->boot();

        return true;
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            $resolvedDir = $this->resolvePluginPath($pluginCode);
            $pluginPath = $this->getPluginPath($pluginCode);
            $pluginNamespace = $this->getPluginNamespace($pluginCode);
            $diag = $this->buildPluginDiagnostics($pluginCode, $resolvedDir, $pluginPath, $pluginNamespace, $pluginPath . '/Plugin.php');
            throw new \Exception(sprintf(
                "Plugin not found (disable): %s — file=%s exists=%s resolved=%s namespace=%s",
                $pluginCode,
                $diag['plugin_file_expected'],
                $diag['plugin_file_FileExists'] ? 'YES' : 'NO',
                $diag['resolvePluginPath'] ?? '<NULL>',
                $diag['plugin_namespace']
            ));
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

        $plugin->cleanup();

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->disable($pluginCode);
        $this->runMigrationsRollback($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        if ($this->isCorePlugin($pluginCode)) {
            throw new \Exception('核心插件不允许删除');
        }

        $pluginPath = $this->getUserPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception('插件不存在');
        }

        File::deleteDirectory($pluginPath);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires): bool
    {
        foreach ($requires as $package => $version) {
            if ($package === 'xboard') {
                // 检查xboard版本
                // 实现版本比较逻辑
            }
        }
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version'])) {
            throw new \Exception('Invalid plugin config or missing version');
        }

        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        $this->disable($pluginCode);
        $this->runMigrations($pluginCode);

        $plugin = $this->loadPlugin($pluginCode);
            if ($plugin) {
                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $plugin->setConfig($values);
                }

                $plugin->update($oldVersion, $newVersion);
            }

        $dbPlugin->update([
            'version' => $newVersion,
            'updated_at' => now(),
        ]);

        $this->enable($pluginCode);

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid();
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception('无法打开插件包文件');
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $configFile = File::glob($extractPath . '/*/config.json');
        if (empty($configFile)) {
            $configFile = File::glob($extractPath . '/config.json');
        }

        if (empty($configFile)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件包格式错误：缺少配置文件');
        }

        $pluginPath = dirname(reset($configFile));
        $config = json_decode(File::get($pluginPath . '/config.json'), true);

        if (!$this->validateConfig($config)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件配置文件格式错误');
        }

        $targetPath = $this->getUserPluginPath($config['code']);
        if (File::exists($targetPath)) {
            $installedConfigPath = $targetPath . '/config.json';
            if (!File::exists($installedConfigPath)) {
                throw new \Exception('已安装插件缺少配置文件，无法判断是否可升级');
            }
            $installedConfig = json_decode(File::get($installedConfigPath), true);

            $oldVersion = $installedConfig['version'] ?? null;
            $newVersion = $config['version'] ?? null;
            if (!$oldVersion || !$newVersion) {
                throw new \Exception('插件缺少版本号，无法判断是否可升级');
            }
            if (version_compare($newVersion, $oldVersion, '<=')) {
                throw new \Exception('上传插件版本不高于已安装版本，无法升级');
            }

            File::deleteDirectory($targetPath);
        }

        File::copyDirectory($pluginPath, $targetPath);
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($extractPath);

        if (Plugin::where('code', $config['code'])->exists()) {
            return $this->update($config['code']);
        }

        return true;
    }

    /**
     * Initializes all enabled plugins from the database.
     * This method ensures that plugins are loaded, and their routes, views,
     * and service providers are registered only once per request cycle.
     */
    public function initializeEnabledPlugins(): void
    {
        if ($this->pluginsInitialized) {
            return;
        }

        $enabledPlugins = Plugin::where('is_enabled', true)->get();

        foreach ($enabledPlugins as $dbPlugin) {
            try {
                $pluginCode = $dbPlugin->code;

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    continue;
                }

                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $pluginInstance->setConfig($values);
                }

                $this->registerServiceProvider($pluginCode);
                $this->loadRoutes($pluginCode);
                $this->loadViews($pluginCode);
                $this->registerPluginCommands($pluginCode, $pluginInstance);

                $pluginInstance->boot();

            } catch (\Exception $e) {
                Log::error("Failed to initialize plugin '{$dbPlugin->code}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * install default plugins
     *
     * Scans BOTH plugins-core (bundled core plugins that ship with every Xboard build)
     * AND plugins/ (user-provided / fork plugins such as OlcRTC).  A plugin is installed
     * once when the plugins table has no record for its code; after successful install
     * it is also enabled so users don't need a manual trip to the admin panel.
     */
    public static function installDefaultPlugins(): void
    {
        $pluginManager = app(self::class);

        $scanDirs = [
            ['dir' => base_path('plugins-core'), 'label' => 'core', 'forceEnable' => true],
            ['dir' => base_path('plugins'),      'label' => 'user', 'forceEnable' => false],
        ];

        foreach ($scanDirs as $scan) {
            if (!File::isDirectory($scan['dir'])) {
                continue;
            }
            foreach (File::directories($scan['dir']) as $directory) {
                $configFile = $directory . '/config.json';
                if (!File::exists($configFile)) {
                    continue;
                }
                $config = json_decode(File::get($configFile), true);
                $code = $config['code'] ?? null;
                if (!$code) {
                    continue;
                }
                if (Plugin::where('code', $code)->exists()) {
                    continue;
                }
                try {
                    $pluginManager->install($code);
                    if ($scan['forceEnable']) {
                        $pluginManager->enable($code);
                    }
                    Log::info(sprintf(
                        'Installed%s %s plugin: %s (v%s)',
                        $scan['forceEnable'] ? ' and enabled' : '',
                        $scan['label'],
                        $code,
                        $config['version'] ?? '0.0.0'
                    ));
                } catch (\Throwable $e) {
                    $msg = sprintf(
                        'Failed to auto-install %s plugin "%s": %s (in %s:%d)',
                        $scan['label'],
                        $code,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    );
                    Log::warning($msg);
                    // Emit to STDERR so `php artisan xboard:install` output shows the
                    // failure immediately — otherwise headless install.sh prints
                    // "✅ plugins installed OK" but a critical plugin is missing.
                    $stderr = null;
                    if (defined('STDERR') && is_resource(STDERR)) {
                        $stderr = STDERR;
                    } else {
                        $fh = @fopen('php://stderr', 'w');
                        if ($fh !== false) {
                            $stderr = $fh;
                        }
                    }
                    if ($stderr !== null) {
                        @fwrite($stderr, "[WARN][PluginManager] {$msg}\n");
                        @fflush($stderr);
                        if ($stderr !== STDERR) {
                            @fclose($stderr);
                        }
                    }
                    if (function_exists('error_log')) {
                        @error_log("[WARN][PluginManager] {$msg}");
                    }
                    @trigger_error("[PluginManager] {$msg}", E_USER_WARNING);
                }
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换（仅处理 type=json 键）。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}