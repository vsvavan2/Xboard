<?php

namespace App\Services\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractPlugin
{
    protected array $config = [];
    protected string $basePath;
    protected string $pluginCode;
    protected string $namespace;

    public function __construct(string $pluginCode)
    {
        $this->pluginCode = $pluginCode;

        // ---------------------------------------------------------------------
        // IMPORTANT: use the exact on-disk folder name as the PSR-4 namespace
        // segment.  Str::studly("olc_rtc") === "OlcRtc" but the real folder is
        // "OlcRTC", which would break the PSR-4 autoloader on case-sensitive
        // filesystems (e.g. Linux ext4).  We therefore derive the namespace
        // from the resolved directory name (same as PluginManager does).
        // ---------------------------------------------------------------------
        $manager    = app(\App\Services\Plugin\PluginManager::class);
        $resolved   = $manager->resolvePluginPath($pluginCode);
        $folderName = $resolved !== null ? basename($resolved) : Str::studly($pluginCode);
        $this->namespace = 'Plugin\\' . $folderName;

        $reflection = new \ReflectionClass($this);
        $this->basePath = dirname($reflection->getFileName());
    }

    /**
     * Получить код (уникальный идентификатор) плагина
     */
    public function getPluginCode(): string
    {
        return $this->pluginCode;
    }

    /**
     * Получить PHP namespace плагина (из имени папки на диске)
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Получить корневой путь к папке плагина на диске
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Установить (переписать) конфигурацию плагина из БД
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Получить значение конфигурации плагина.
     * Если $key=null — возвращает ВЕСЬ массив конфига.
     * Если ключа нет — возвращает $default.
     */
    public function getConfig(?string $key = null, $default = null): mixed
    {
        $config = $this->config;
        if ($key) {
            $config = $config[$key] ?? $default;
        }
        return $config;
    }

    /**
     * Отрендерить Blade-шаблон плагина (папка views внутри плагина)
     */
    protected function view(string $view, array $data = [], array $mergeData = []): \Illuminate\Contracts\View\View
    {
        return view(Str::studly($this->pluginCode) . '::' . $view, $data, $mergeData);
    }

    /**
     * Зарегистрировать слушатель СОБЫТИЯ (action hook), вызывается HookManager::call()
     */
    protected function listen(string $hook, callable $callback, int $priority = 20): void
    {
        HookManager::register($hook, $callback, $priority);
    }

    /**
     * Зарегистрировать слушатель-ФИЛЬТР (filter hook), вызывается HookManager::filter()
     */
    protected function filter(string $hook, callable $callback, int $priority = 20): void
    {
        HookManager::registerFilter($hook, $callback, $priority);
    }

    /**
     * Удалить (отписаться) все слушатели плагина с данного hook-события
     */
    protected function removeListener(string $hook): void
    {
        HookManager::remove($hook);
    }

    /**
     * Зарегистрировать одну Artisan-консольную команду (по FQCN класса)
     */
    protected function registerCommand(string $commandClass): void
    {
        if (class_exists($commandClass)) {
            app('Illuminate\Contracts\Console\Kernel')->registerCommand(new $commandClass());
        }
    }

    /**
     * Авто-регистрация всех Artisan-команд плагина из папки Commands (любой файл *.php внутри).
     * Вызывается автоматически ядром плагинов.
     */
    public function registerCommands(): void
    {
        $commandsPath = $this->basePath . '/Commands';
        if (File::exists($commandsPath)) {
            $files = File::glob($commandsPath . '/*.php');
            foreach ($files as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                $commandClass = $this->namespace . '\\Commands\\' . $className;
                
                if (class_exists($commandClass)) {
                    $this->registerCommand($commandClass);
                }
            }
        }
    }

    /**
     * Мгновенно прервать текущий HTTP-запрос и вернуть клиенту свой ответ
     * (используется для кастомных страниц плагина вместо роутов).
     *
     * @param Response|string|array $response
     * @return never
     */
    protected function intercept(Response|string|array $response): never
    {
        HookManager::intercept($response);
    }

    /**
     * Вызывается КАЖДЫЙ HTTP-запрос когда плагин подключён и включён (boot-time).
     * Регистрируйте здесь слушатели хуков, фильтры и роуты плагина.
     */
    public function boot(): void
    {
        // Логика инициализации при каждом запуске плагина
    }

    /**
     * Вызывается ОДИН РАЗ при нажатии «Установить плагин» в админке (install-time).
     * Создавайте здесь таблицы БД, директории, дефолтные настройки.
     */
    public function install(): void
    {
        // Логика установки плагина (миграции БД и т.д.)
    }

    /**
     * Вызывается ОДИН РАЗ при нажатии «Удалить плагин» в админке (cleanup-time).
     * Чистите здесь файлы, таблицы, ключи — всё что создавали в install()
     * (если пользователь явно сказал удалить плагин).
     */
    public function cleanup(): void
    {
        // Логика очистки при полном удалении плагина
    }

    /**
     * Вызывается ОДИН РАЗ после обновления версии плагина в админке (обновили папку с кодом).
     * @param string $oldVersion старая SemVer (например "1.0.0")
     * @param string $newVersion новая SemVer (например "1.1.0")
     */
    public function update(string $oldVersion, string $newVersion): void
    {
        // Логика миграции конфигов при смене версии плагина
    }

    /**
     * Получить публичный URL к статичному ресурсу плагина (картинки/CSS/JS, публиковались в public/plugins/).
     */
    protected function asset(string $path): string
    {
        return asset('plugins/' . $this->pluginCode . '/' . ltrim($path, '/'));
    }

    /**
     * [устарело, оставлено для совместимости] Получить отдельное значение из конфига плагина.
     * Рекомендуется пользоваться универсальным getConfig($key, $default) выше.
     */
    protected function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Получить путь к папке database/migrations плагина (Laravel миграции).
     */
    protected function getMigrationsPath(): string
    {
        return $this->basePath . '/database/migrations';
    }

    /**
     * Получить путь к папке resources/views плагина (Blade-шаблоны).
     */
    protected function getViewsPath(): string
    {
        return $this->basePath . '/resources/views';
    }

    /**
     * Получить путь к папке resources/assets плагина (статика для публикации).
     */
    protected function getAssetsPath(): string
    {
        return $this->basePath . '/resources/assets';
    }

    /**
     * Register plugin scheduled tasks. Plugins can override this method.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        // Plugin can override this method to register scheduled tasks
    }
}