<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

$_olmStubFn = function () {
    $banner = '💡 Работаем ТОЛЬКО с OlcRTC VPN (WebRTC). V2Ray/Clash/SingBox отключены. Откройте раздел «ЛК → Моя подписка» — там ваш OlcRTC-ключ olcrtc://jitsi?datachannel@… и клиенты OlcBox / owenclave.';
    return response()->json([
        'status' => 'success',
        'data'   => [
            'subscribe_disabled' => true,
            'subscribe_url'      => url('/#/user/subscription'),
            'token'              => null,
            'plan'               => null,
            'reset_day'          => 0,
            'transfer_enable'    => 0,
            'u'                  => 0,
            'd'                  => 0,
            'device_limit'       => 0,
            'speed_limit'        => 0,
            'expired_at'         => null,
            'servers'            => [],
            'olcrtc_only_banner' => $banner,
        ],
        'message' => 'Режим OlcRTC-ONLY. Подписки V2Ray/Clash/SingBox отключены — используйте раздел «Моя подписка» для получения OlcRTC ключа.',
    ], 200);
};
Route::any('/api/v1/user/getSubscribe', $_olmStubFn)->middleware('web');
Route::any('/user/getSubscribe', $_olmStubFn)->middleware('web');
Route::any('/api/v1/user/server/fetch', function () use (&$_olmStubFn) {
    $banner = $_olmStubFn()->getData(true)['data']['olcrtc_only_banner'] ?? '';
    return response()->json(['data' => [], 'message' => $banner], 200)->header('ETag', '"olcrtc-only-empty-servers"');
})->middleware('web');
Route::any('/user/server/fetch', function () use (&$_olmStubFn) {
    $banner = $_olmStubFn()->getData(true)['data']['olcrtc_only_banner'] ?? '';
    return response()->json(['data' => [], 'message' => $banner], 200)->header('ETag', '"olcrtc-only-empty-servers"');
})->middleware('web');

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

Route::post('/api/olcrtc/claim-trial', function (Request $request) {
    if (!auth()->check()) {
        return response()->json([
            'status'  => 'guest',
            'success' => false,
            'message' => 'Требуется вход в личный кабинет для получения тестовой подписки.'
        ], 401);
    }
    try {
        $user = \App\Models\User::find(auth()->id());
        if (!$user) {
            return response()->json(['status'=>'fail','success'=>false,'message'=>'Пользователь не найден (cookie?) — перелогиньтесь.'],400);
        }
        $settingKey = 'olcrtc_trial_claimed_'.((int)$user->id);
        $already = (int)\App\Models\Setting::where('name', $settingKey)->value('value');
        if ($already >= 1) {
            $msg = '⚠️ Тестовая подписка на 6 часов уже была получена на этот аккаунт ранее. '
                 . 'Каждый аккаунт получает пробный период ОДИН РАЗ. Если закончилось — оплатите любой тариф, ключи выдаются сразу.';
            return response()->json([
                'status'   => 'fail',
                'success'  => false,
                'message'  => $msg,
                'already'  => true,
            ], 409);
        }
        if ($user->banned) {
            return response()->json(['status'=>'fail','success'=>false,'message'=>'Аккаунт заблокирован — напишите в поддержку через Тикет.'],403);
        }
        $planId = (int)admin_setting('try_out_plan_id', 1);
        $hours  = max(1, (int)admin_setting('try_out_hour', 6));
        $plan   = \App\Models\Plan::find($planId);
        if (!$plan) {
            return response()->json(['status'=>'fail','success'=>false,'message'=>'Тестовый план не настроен — сообщите администратору.'],500);
        }
        if ($user->isActive()) {
            return response()->json([
                'status'  => 'fail',
                'success' => false,
                'already' => true,
                'message' => '✅ У вас уже действует активная подписка. Тестовая подписка нужна только новым пользователям без купленного тарифа.',
            ], 409);
        }
        $user->plan_id         = $plan->id;
        $user->group_id        = $plan->group_id;
        $transferBytes         = (int)($plan->transfer_enable ?? 10737418240);
        if ($transferBytes <= 0) $transferBytes = 10737418240;
        $user->transfer_enable = $transferBytes;
        $user->expired_at      = time() + ($hours * 3600);
        $user->speed_limit     = $plan->speed_limit ?? 0;
        $user->device_limit    = $plan->device_limit ?? 0;
        $user->save();

        try {
            $mgr = \App::make(\Plugin\OlcRTC\Services\OlcRTCManagerClient::class);
        } catch (\Throwable $e) {
            $mgr = null;
        }
        if ($mgr === null) {
            try {
                $plugConf = [];
                foreach (['manager_url','manager_api_key','default_provider','default_transport','default_dns','auth_token'] as $k) {
                    $plugConf[$k] = (string)plugin_setting('olc_rtc', $k, '');
                }
                if (empty($plugConf['manager_url'])) {
                    try {
                        $plugRow = \App\Models\Plugin::where('code', 'olc_rtc')->first();
                        if ($plugRow && !empty($plugRow->config)) {
                            $cfg = @json_decode($plugRow->config, true);
                            if (is_array($cfg)) {
                                foreach ($plugConf as $k=>$v) if (isset($cfg[$k]) && $v === '') $plugConf[$k] = (string)$cfg[$k];
                            }
                        }
                    } catch (\Throwable $e) {}
                }
                if (empty($plugConf['manager_url'])) $plugConf['manager_url'] = (string)admin_setting('olcrtc_manager_url', 'http://olcrtc-manager:8080');
                if (empty($plugConf['default_dns']))    $plugConf['default_dns'] = (string)admin_setting('olcrtc_default_dns', '77.88.8.8:53');
                if (empty($plugConf['default_provider']))$plugConf['default_provider'] = 'jitsi';
                if (empty($plugConf['default_transport']))$plugConf['default_transport'] = 'datachannel';
                $mgr = new \Plugin\OlcRTC\Services\OlcRTCManagerClient(
                    rtrim($plugConf['manager_url'],'/'),
                    $plugConf['manager_api_key'],
                    $plugConf['default_provider'],
                    $plugConf['default_transport'],
                    $plugConf['default_dns'],
                    $plugConf['auth_token']
                );
            } catch (\Throwable $e) { $mgr = null; }
        }
        $preCreateError = null;
        if ($mgr !== null) {
            try {
                $mgr->createOrUpdateInstance($user->id, (int)$user->expired_at, 'trial-claim-'.date('Ymd-His'));
            } catch (\Throwable $e) {
                $preCreateError = $e->getMessage();
            }
        }

        try {
            $row = \App\Models\Setting::where('name', $settingKey)->first();
            if (!$row) {
                $row = new \App\Models\Setting();
                $row->name = $settingKey;
                $row->created_at = time();
            }
            $row->value = '1';
            $row->updated_at = time();
            $row->save();
        } catch (\Throwable $e) {}

        $freshUser = \App\Models\User::find($user->id);
        $expText = date('d.m.Y H:i', (int)($freshUser->expired_at ?? time()));
        $mgrOk   = $preCreateError === null;
        return response()->json([
            'status'   => 'success',
            'success'  => true,
            'trial_hours' => $hours,
            'plan_id'  => $planId,
            'plan_title' => (string)($plan->name ?? $plan->title ?? 'Тест (Базовый)'),
            'expired_at_text' => $expText,
            'message'  => '✅ Тестовая подписка OlcRTC на '.$hours.' часов АКТИВИРОВАНА! Действительна до '.$expText.'. '
                        . ($mgrOk ? 'VPN-инстанс создан на OlcRTC-manager — ключ появится в виджете ниже автоматически через 3–10 секунд.'
                                : '⚠️ Ключ будет создан при следующем открытии ЛК ('.$preCreateError.') — обновите страницу через 1 минуту.'),
            'manager_ok' => $mgrOk,
            'manager_error' => $preCreateError,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'status'   => 'fail',
            'success'  => false,
            'message'  => '❌ Ошибка активации пробной подписки: '.$e->getMessage(),
        ], 500);
    }
})->middleware('web');

Route::post('/api/olcrtc/recreate', function (Request $request) {
    if (!auth()->check()) {
        return response()->json(['status'=>'guest','success'=>false,'message'=>'Требуется вход в ЛК.'],401);
    }
    try {
        $user = \App\Models\User::find(auth()->id());
        if (!$user) return response()->json(['status'=>'fail','message'=>'Пользователь не найден — перелогиньтесь.'],400);
        if ($user->banned) return response()->json(['status'=>'fail','message'=>'Аккаунт заблокирован.'],403);
        if (!$user->isActive()) return response()->json([
            'status'=>'fail','success'=>false,
            'message'=>'⚠️ У вас ещё нет активной подписки. Купите тариф в разделе «Оплата» или активируйте бесплатный тест 6 часов.'
        ], 409);

        // Instantiate OlcRTCManagerClient manual fallback (exactly same as claim-trial)
        $mgr = null;
        try { $mgr = \App::make(\Plugin\OlcRTC\Services\OlcRTCManagerClient::class); } catch (\Throwable $e){}
        if ($mgr === null) {
            try {
                $plugConf = [];
                foreach (['manager_url','manager_api_key','default_provider','default_transport','default_dns','auth_token'] as $k) {
                    $plugConf[$k] = (string)plugin_setting('olc_rtc', $k, '');
                }
                if (empty($plugConf['manager_url'])) {
                    try {
                        $plugRow = \App\Models\Plugin::where('code','olc_rtc')->first();
                        if ($plugRow && !empty($plugRow->config)) {
                            $cfg = @json_decode($plugRow->config, true);
                            if (is_array($cfg)) foreach ($plugConf as $k=>$v) if (isset($cfg[$k]) && $v==='') $plugConf[$k]=(string)$cfg[$k];
                        }
                    } catch (\Throwable $e) {}
                }
                if (empty($plugConf['manager_url'])) $plugConf['manager_url']=(string)admin_setting('olcrtc_manager_url','http://olcrtc-manager:8080');
                if (empty($plugConf['default_dns'])) $plugConf['default_dns']=(string)admin_setting('olcrtc_default_dns','77.88.8.8:53');
                if (empty($plugConf['default_provider'])) $plugConf['default_provider']='jitsi';
                if (empty($plugConf['default_transport'])) $plugConf['default_transport']='datachannel';
                $mgr = new \Plugin\OlcRTC\Services\OlcRTCManagerClient(
                    rtrim($plugConf['manager_url'],'/'),
                    $plugConf['manager_api_key'],
                    $plugConf['default_provider'],
                    $plugConf['default_transport'],
                    $plugConf['default_dns'],
                    $plugConf['auth_token']
                );
            } catch (\Throwable $e){ $mgr = null; }
        }
        $createError = null;
        if ($mgr === null) $createError = 'OlcRTC Manager client not available';
        else {
            try {
                $mgr->createOrUpdateInstance($user->id, (int)$user->expired_at, 'manual-recreate-'.date('Ymd-His'));
            } catch (\Throwable $e) { $createError = $e->getMessage(); }
        }

        if ($createError !== null) {
            return response()->json([
                'status'=>'fail','success'=>false,'create_error'=>$createError,
                'message'=>'❌ Не удалось создать OlcRTC инстанс: '.$createError,
            ], 500);
        }
        return response()->json([
            'status'=>'success','success'=>true,
            'expired_at_text' => date('d.m.Y H:i', (int)($user->expired_at ?? time())),
            'message'=>'✅ OlcRTC инстанс создан/обновлён! Ключ появится через 3–6 секунд — нажмите «🔄 Пересоздать» в виджете или подождите авто-обновление.',
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['status'=>'fail','success'=>false,'message'=>'❌ Ошибка recreate: '.$e->getMessage()],500);
    }
})->middleware('web');

Route::get('/api/olcrtc/trial-status', function (Request $request) {
    if (!auth()->check()) {
        return response()->json(['status'=>'guest','claimed'=>false,'active'=>false],200);
    }
    try {
        $user = \App\Models\User::find(auth()->id());
        $claimed = 0;
        try {
            $claimed = (int)\App\Models\Setting::where('name','olcrtc_trial_claimed_'.((int)$user->id))->value('value');
        } catch (\Throwable $e) {}
        return response()->json([
            'status'   => 'ok',
            'claimed'  => $claimed >= 1,
            'active'   => $user ? $user->isActive() : false,
            'expired_at' => $user ? $user->expired_at : null,
            'plan_id'    => $user ? $user->plan_id : null,
            'try_out_hour' => (int)admin_setting('try_out_hour', 6),
        ],200);
    } catch (\Throwable $e) {
        return response()->json(['status'=>'fail','claimed'=>false,'active'=>false,'error'=>$e->getMessage()],500);
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