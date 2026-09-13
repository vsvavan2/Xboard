<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

Route::get('/api/olcrtc/widget', function (Request $request) {
    if (!auth()->check()) {
        return response()->json([
            'status' => 'guest',
            'msg' => 'Требуется вход в личный кабинет'
        ]);
    }
    try {
        $controller = app(\Plugin\OlcRTC\Controllers\OlcRTCController::class);
        $request->setUserResolver(function () {
            return auth()->user();
        });
        $resp = $controller->info($request);
        if ($resp instanceof \Illuminate\Http\JsonResponse) {
            $raw = $resp->getData(true);
        } else {
            $raw = is_array($resp) ? $resp : json_decode(json_encode($resp, JSON_UNESCAPED_UNICODE), true);
        }
        if (is_array($raw) && isset($raw['data']) && is_array($raw['data']) && (
            isset($raw['data']['uri']) ||
            isset($raw['data']['banner']) ||
            isset($raw['data']['client_downloads']) ||
            isset($raw['data']['instance'])
        )) {
            $out = $raw['data'];
        } else {
            $out = $raw;
        }
        $out['widget_injected'] = true;
        $out['widget_via_web_auth'] = true;
        if (empty($out['status']) && !empty($raw['status'])) {
            $out['status'] = $raw['status'];
        }
        return response()->json($out);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'error',
            'msg' => $e->getMessage(),
            'uri' => null,
            'banner' => '❌ Ошибка получения ключа: ' . $e->getMessage() . ' — попробуйте перезагрузить страницу или напишите в Тикет.',
            'primary_cta' => '🔄 Обновить страницу',
            'uri_copy_hint' => 'Если ошибка повторяется более 3 минут — напишите в поддержку.',
            'client_downloads' => [
                ['platform' => 'Windows / macOS / Linux', 'name' => 'OlcBox (рекомендуется для ПК)', 'url' => 'https://github.com/alananisimov/olcbox/releases', 'install_hint' => 'Установите → ➕ → Import from clipboard → Connect'],
                ['platform' => 'Android', 'name' => 'owenclave (быстрее + обход DPI)', 'url' => 'https://github.com/owenewans/owenclave/releases', 'install_hint' => 'Установите APK → ➕ → Import from clipboard → Play']
            ]
        ], 500);
    }
})->middleware('web');

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');