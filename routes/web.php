<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
| The user SPA (Umi/React) authenticates with a Sanctum Bearer token stored in
| localStorage ("VUE_NAIVE_ACCESS_TOKEN"), it does NOT establish a cookie
| session — and the "web" middleware group has StartSession commented out, so
| auth()->check() is always false on these routes.  Resolve the caller either
| from the (cookie) session, from the Sanctum guard (Authorization: Bearer) or
| from an explicit ?token= query parameter.
*/
$_olcrtcUserFn = function (Request $request) {
    if (auth()->check()) {
        return auth()->user();
    }
    try {
        $u = \Auth::guard('sanctum')->user();
        if ($u instanceof \App\Models\User) {
            return $u;
        }
    } catch (\Throwable $e) {
    }
    $auth = (string) $request->header('Authorization', '');
    if ($auth === '') {
        $auth = (string) $request->input('token', '');
    }
    if ($auth !== '') {
        try {
            $u = \App\Services\AuthService::findUserByBearerToken($auth);
            if ($u instanceof \App\Models\User) {
                return $u;
            }
        } catch (\Throwable $e) {
        }
    }
    return null;
};

/*
| Legacy subscribe endpoints used by the user SPA.  They return an EMPTY server
| list (no V2Ray/Clash/SingBox nodes exist in this product) but a REAL OlcRTC
| subscription URL of the correct format, so "copy subscription link" in the SPA
| hands the customer a working olcrtc:// link instead of a dead legacy URL.
*/
$_olmStubFn = function (Request $request) use ($_olcrtcUserFn) {
    $banner = '💡 Работаем ТОЛЬКО с OlcRTC VPN (WebRTC). V2Ray/Clash/SingBox отключены. Откройте раздел «ЛК → Моя подписка» — там ваш OlcRTC-ключ olcrtc://jitsi?datachannel@… и клиенты OlcBox / owenclave.';
    $user   = $_olcrtcUserFn($request);
    $subUrl = $user ? url('/api/olcrtc/sub?token=' . $user->token) : url('/');
    return response()->json([
        'status' => 'success',
        'data'   => [
            'subscribe_disabled' => false,
            'subscribe_url'      => $subUrl,
            'token'              => $user->token ?? null,
            'plan'               => null,
            'reset_day'          => 0,
            'transfer_enable'    => (int)($user->transfer_enable ?? 0),
            'u'                  => (int)($user->u ?? 0),
            'd'                  => (int)($user->d ?? 0),
            'device_limit'       => (int)($user->device_limit ?? 0),
            'speed_limit'        => (int)($user->speed_limit ?? 0),
            'expired_at'         => $user->expired_at ?? null,
            'servers'            => [],
            'olcrtc_only_banner' => $banner,
            'olcrtc_subscribe_url' => $subUrl,
        ],
        'message' => 'Режим OlcRTC-ONLY. Подписка выдаётся в формате OlcRTC (olcrtc://jitsi?datachannel@…). Скопируйте ссылку подписки или ключ в разделе «Моя подписка».',
    ], 200);
};
Route::any('/api/v1/user/getSubscribe', $_olmStubFn)->middleware('web');
Route::any('/user/getSubscribe', $_olmStubFn)->middleware('web');
Route::any('/api/v1/user/server/fetch', function (Request $request) use (&$_olmStubFn) {
    $banner = $_olmStubFn($request)->getData(true)['data']['olcrtc_only_banner'] ?? '';
    return response()->json(['data' => [], 'message' => $banner], 200)->header('ETag', '"olcrtc-only-empty-servers"');
})->middleware('web');
Route::any('/user/server/fetch', function (Request $request) use (&$_olmStubFn) {
    $banner = $_olmStubFn($request)->getData(true)['data']['olcrtc_only_banner'] ?? '';
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

/*
| OlcRTC manager connection settings.
| Priority: plugin row config (seeded by `xboard:install`) → plugin settings →
| admin settings → container ENV (the very same OLCRMGR_* variables the manager
| container itself reads) → hardcoded defaults.  Resolving the ENV fallback is
| what makes the widget work out-of-the-box: the API key can never drift from
| the one the manager expects when both containers share `OLCRMGR_API_KEY`.
*/
$_olcrtcMgrConfFn = function () {
    $conf = [
        'manager_url'       => '',
        'manager_api_key'   => '',
        'default_provider'  => '',
        'default_transport' => '',
        'default_dns'       => '',
        'auth_token'        => '',
    ];
    $keys = array_keys($conf);

    // 1) Plugin row config JSON (authoritative — written by xboard:install/auto-seed)
    try {
        $row = \App\Models\Plugin::where('code', 'olc_rtc')->first();
        if ($row && !empty($row->config)) {
            $cfg = is_array($row->config) ? $row->config : (json_decode((string) $row->config, true) ?: []);
            foreach ($keys as $k) {
                if (isset($cfg[$k]) && (string) $cfg[$k] !== '') {
                    $conf[$k] = (string) $cfg[$k];
                }
            }
        }
    } catch (\Throwable $e) {
    }

    // 2) plugin_setting() overrides
    try {
        foreach ($keys as $k) {
            $v = (string) plugin_setting('olc_rtc', $k, '');
            if ($v !== '') {
                $conf[$k] = $v;
            }
        }
    } catch (\Throwable $e) {
    }

    // 3) admin settings
    foreach ([
        'manager_url'       => 'olcrtc_manager_url',
        'manager_api_key'   => 'olcrtc_manager_api_key',
        'default_provider'  => 'olcrtc_default_provider',
        'default_transport' => 'olcrtc_default_transport',
        'default_dns'       => 'olcrtc_default_dns',
    ] as $k => $setting) {
        if ($conf[$k] === '') {
            try {
                $v = (string) admin_setting($setting, '');
                if ($v !== '') {
                    $conf[$k] = $v;
                }
            } catch (\Throwable $e) {
            }
        }
    }

    // 4) container ENV
    foreach ([
        'manager_url'       => 'OLCRMGR_URL',
        'manager_api_key'   => 'OLCRMGR_API_KEY',
        'default_provider'  => 'OLCRMGR_DEFAULT_PROVIDER',
        'default_transport' => 'OLCRMGR_DEFAULT_TRANSPORT',
        'default_dns'       => 'OLCRMGR_DEFAULT_DNS',
        'auth_token'        => 'OLCRMGR_DEFAULT_TOKEN',
    ] as $k => $envName) {
        if ($conf[$k] === '') {
            try {
                $v = (string) env($envName, '');
                if ($v !== '') {
                    $conf[$k] = $v;
                }
            } catch (\Throwable $e) {
            }
        }
    }

    if ($conf['manager_url'] === '')       $conf['manager_url'] = 'http://olcrtc-manager:8080';
    if ($conf['default_provider'] === '')  $conf['default_provider'] = 'jitsi';
    if ($conf['default_transport'] === '') $conf['default_transport'] = 'datachannel';
    if ($conf['default_dns'] === '')       $conf['default_dns'] = '77.88.8.8:53';

    return $conf;
};

/*
| Self-healing API-key picker.  The plugin/admin settings may hold a stale or
| blank key (e.g. .env rotated after install, or the admin form saved an empty
| field) while the running olcrtc-manager validates against its own
| OLCRMGR_API_KEY.  Probe the candidates and keep the first one the manager
| actually accepts, so a key mismatch never leaves the customer widget on
| "Ключ готовится (HTTP 401)".
*/
$_olcrtcMgrKeyFn = function () use ($_olcrtcMgrConfFn) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $conf = $_olcrtcMgrConfFn();
    $url  = rtrim((string) $conf['manager_url'], '/');

    $cands = [];
    foreach ([(string) $conf['manager_api_key'], (string) env('OLCRMGR_API_KEY', '')] as $k) {
        if ($k !== '' && !in_array($k, $cands, true)) {
            $cands[] = $k;
        }
    }
    if (!$cands) {
        return $cache = '';
    }
    foreach ($cands as $k) {
        try {
            $r = \Illuminate\Support\Facades\Http::withToken($k)
                ->timeout(4)
                ->acceptJson()
                ->get($url . '/api/v1/instances');
            if ($r->status() !== 401 && $r->status() !== 403) {
                return $cache = $k;
            }
        } catch (\Throwable $e) {
            // manager unreachable — keep the first candidate, the widget reports the error
            return $cache = $cands[0];
        }
    }
    return $cache = $cands[0];
};

$_olcrtcMgrFn = function () use ($_olcrtcMgrConfFn, $_olcrtcMgrKeyFn) {
    $conf = $_olcrtcMgrConfFn();
    return new \Plugin\OlcRTC\Services\OlcRTCManagerClient(
        rtrim((string) $conf['manager_url'], '/'),
        (string) $_olcrtcMgrKeyFn(),
        (string) $conf['default_provider'],
        (string) $conf['default_transport'],
        (string) $conf['default_dns'],
        (string) $conf['auth_token']
    );
};

/*
| Self-contained OlcRTC key endpoint for the in-page widget.  It does NOT rely
| on the OlcRTC plugin being "enabled" (a disabled plugin used to answer with a
| bare string → the widget silently stayed on "Готовится").  Every manager call
| is isolated and its failure is reported in `diag`, so the customer area shows
| the real reason instead of a spinner forever.
*/
/*
| Единая логика «бесплатный тест 3 дня vs оплаченный тариф» и остатка срока для
| всех эндпоинтов ЛК (виджет, trial-status).  Точный признак теста — сохранённый
| момент окончания пробного периода (olcrtc_trial_expires_<id>); для аккаунтов,
| активировавших тест до появления этой метки, используется окно
| «осталось <= try_out_hour + 2 ч» (после покупки тарифа срок уходит далеко
| вперёд → это уже не тест).
*/
$_olcrtcTrialInfoFn = function ($user) {
    $uid       = (int) ($user->id ?? 0);
    $expTs     = (int) ($user->expired_at ?? 0);
    $hoursLeft = $expTs > 0 ? (int) ceil(max(0, $expTs - time()) / 3600) : 0;
    $trialHours = max(1, (int) admin_setting('try_out_hour', 72));

    $claimed    = false;
    $trialExpTs = 0;
    try {
        $claimed    = (int) \App\Models\Setting::where('name', 'olcrtc_trial_claimed_' . $uid)->value('value') >= 1;
        $trialExpTs = (int) \App\Models\Setting::where('name', 'olcrtc_trial_expires_' . $uid)->value('value');
    } catch (\Throwable $e) {
    }

    if ($trialExpTs > 0) {
        $isTrial = $claimed && $expTs > 0 && $expTs <= ($trialExpTs + 3600);
    } else {
        $isTrial = $claimed && $hoursLeft > 0 && $hoursLeft <= ($trialHours + 2);
    }

    $daysLeft = intdiv($hoursLeft, 24);
    $hoursRem = $hoursLeft % 24;
    if ($hoursLeft <= 0) {
        $leftText = 'срок истёк.';
    } elseif ($daysLeft >= 1) {
        $leftText = 'осталось ' . $daysLeft . ' дн.' . ($hoursRem > 0 ? ' ' . $hoursRem . ' ч.' : '');
    } else {
        $leftText = 'осталось ' . $hoursLeft . ' ч.';
    }

    return [
        'is_trial'      => $isTrial,
        'claimed'       => $claimed,
        'hours_left'    => $hoursLeft,
        'days_left'     => $daysLeft,
        'left_text'     => $leftText,
        'exp_text'      => $expTs > 0 ? date('d.m.Y H:i', $expTs) : '',
        'expiring_soon' => ($hoursLeft > 0 && $hoursLeft <= 24),
        'trial_hours'   => $trialHours,
    ];
};

/*
| Резолвер «триального» плана. Устойчив к рассинхрону настройки: try_out_plan_id
| мог указывать на несуществующий/скрытый план (например на установке планы
| засеялись с другими id, или ещё не созданы). Порядок поиска:
|   1) план по сохранённому try_out_plan_id;
|   2) наш «Базовый» план по имени;
|   3) первый показываемый (show=1) план, иначе самый первый план в таблице.
| Найденный id записывается обратно в настройку, чтобы админка/SPA показывали
| корректный триальный тариф (self-healing, идемпотентно).
*/
$_olcrtcTrialPlanFn = function () {
    $configured = (int) admin_setting('try_out_plan_id', 0);

    $plan = null;
    if ($configured > 0) {
        try { $plan = \App\Models\Plan::find($configured); } catch (\Throwable $e) { $plan = null; }
    }
    if (!$plan) {
        foreach (['🥉 Базовый (30 дней)', '🥉 Базовый', 'Базовый'] as $name) {
            try {
                $p = \App\Models\Plan::where('name', $name)->first();
                if ($p) { $plan = $p; break; }
            } catch (\Throwable $e) {}
        }
    }
    if (!$plan) {
        try {
            $plan = \App\Models\Plan::where('show', 1)->orderBy('sort')->first()
                ?: \App\Models\Plan::orderBy('id')->first();
        } catch (\Throwable $e) { $plan = null; }
    }
    if ($plan && (int) $plan->id !== $configured) {
        try {
            \App\Models\Setting::updateOrCreate(
                ['name' => 'try_out_plan_id'],
                ['value' => (string) $plan->id]
            );
        } catch (\Throwable $e) {}
    }
    return $plan;
};

/*
| olcrtc://<Provider>?<Transport>@<RoomID>#<key>$<MIMO>
| «$<MIMO>» — свободная метка подключения из спецификации URI v1. Она не
| влияет на работу ключа и её присутствие делает выдаваемый ключ ровно такого
| же вида, как эталонный ключ из гайда (…/olcrtc-xxxx#<64hex>$olc), поэтому
| метку СОХРАНЯЕМ. Здесь только нормализуем переносы строк и лишние пробелы,
| чтобы ключ и ссылка-подписка оставались корректными в одну строку.
*/
$_olcrtcCleanUriFn = function ($uri) {
    $uri = (string) $uri;
    $uri = str_replace(["\r\n", "\r"], "\n", $uri);
    // Схлопываем пробелы/табы, но НЕ трогаем переводы строк (в sub.md каждая
    // строка — отдельная запись).
    $uri = preg_replace('/[^\S\n]+/u', ' ', $uri);
    return trim((string) $uri);
};

Route::get('/api/olcrtc/widget', function (Request $request) use ($_olcrtcUserFn, $_olcrtcMgrConfFn, $_olcrtcMgrFn, $_olcrtcTrialInfoFn, $_olcrtcCleanUriFn) {
    $user = $_olcrtcUserFn($request);
    if (!$user) {
        return response()->json([
            'status' => 'guest',
            'msg' => 'Требуется вход в личный кабинет'
        ]);
    }
    $user = \App\Models\User::find($user->id);
    if (!$user) {
        return response()->json(['status' => 'guest', 'msg' => 'Сессия устарела — войдите в кабинет заново']);
    }

    $clients = [
        ['platform' => 'Windows / macOS / Linux (десктоп)', 'name' => 'OlcBox — рекомендуется для ПК и ноутбуков', 'url' => 'https://github.com/alananisimov/olcbox/releases', 'install_hint' => 'Скачайте .msi (Windows) или .dmg (macOS) → установите → ➕ → Paste from Clipboard → Connect'],
        ['platform' => 'Android (телефоны/планшеты)', 'name' => 'owenclave — рекомендуется', 'url' => 'https://github.com/owenewans/owenclave/releases', 'install_hint' => 'Скачайте app-*-release.apk → включите Неизвестные источники → установите → ➕ → Import from clipboard → Play'],
    ];

    $subUrl  = url('/api/olcrtc/sub?token=' . $user->token);
    $yamlUrl = url('/api/olcrtc/yaml?token=' . $user->token);

    $conf = $_olcrtcMgrConfFn();
    $expTs = $user->expired_at
        ? (is_numeric($user->expired_at) ? (int) $user->expired_at : (int) strtotime((string) $user->expired_at))
        : 0;
    if ($expTs <= 0) $expTs = time() + 72 * 3600;

    // ---- тип подписки (бесплатный тест 3 дня / оплаченный тариф) и остаток срока ----
    $planId    = (int) ($user->plan_id ?? 0);
    $planTitle = '';
    try {
        $planTitle = (string) (\App\Models\Plan::find($planId)->name ?? '');
    } catch (\Throwable $e) {
    }

    $trial        = $_olcrtcTrialInfoFn($user);
    $isTrial      = (bool) $trial['is_trial'];
    $hoursLeft    = (int) $trial['hours_left'];
    $daysLeft     = (int) $trial['days_left'];
    $leftText     = (string) $trial['left_text'];
    $expText      = $trial['exp_text'] !== '' ? (string) $trial['exp_text'] : date('d.m.Y H:i', $expTs);
    $expiringSoon = (bool) $trial['expiring_soon'];

    $diag = [
        'manager_url'   => $conf['manager_url'],
        'api_key_set'   => $conf['manager_api_key'] !== '',
        'user_id'       => $user->id,
        'user_token'    => $user->token,
        'is_active'     => $user->isActive(),
        'is_trial'      => $isTrial,
        'plan_id'       => $planId,
        'expired_at'    => $user->expired_at,
        'expired_at_text' => $expText,
    ];

    $uri = null;
    $createError = null;
    $rawInstances = [];

    $mgr = null;
    try {
        $mgr = $_olcrtcMgrFn();
    } catch (\Throwable $e) {
        $createError = 'client init: ' . $e->getMessage();
    }

    if ($mgr !== null) {
        // manager liveness (never throws)
        try {
            $h = \Illuminate\Support\Facades\Http::timeout(4)->acceptJson()
                ->get(rtrim((string) $conf['manager_url'], '/') . '/healthz');
            $diag['manager_healthz_http'] = $h->status();
        } catch (\Throwable $e) {
            $diag['manager_healthz_http'] = 'ERR ' . $e->getMessage();
        }

        // existing instance + uri (primary slot) + все слоты (три ключа)
        try {
            $inst = $mgr->getUserInstance($user->id);
            $diag['instance_found'] = is_array($inst) && !empty($inst['instance']);
            if (is_array($inst) && !empty($inst['uri'])) {
                $uri = (string) $inst['uri'];
            }
            if (is_array($inst) && !empty($inst['instances']) && is_array($inst['instances'])) {
                $rawInstances = $inst['instances'];
            }
        } catch (\Throwable $e) {
            $diag['instance_error'] = $e->getMessage();
        }

        // Всегда досоздаём/обновляем все три слота: так уже существующие
        // пользователи получают дополнительные ключи без ручных действий,
        // а один хост, упавший при создании, не лишает их остальных.
        try {
            $mgr->provisionAllSlots($user->id, $expTs);
            $diag['slots_provisioned'] = true;
        } catch (\Throwable $e) {
            $diag['slots_error'] = $e->getMessage();
            if ($createError === null) $createError = $e->getMessage();
        }

        if ($uri === null || $uri === '') {
            // Перечитываем после провижининга (нужно для первичной выдачи)
            try {
                $inst = $mgr->getUserInstance($user->id);
                $diag['instance_found_after_create'] = is_array($inst) && !empty($inst['instance']);
                if (is_array($inst) && !empty($inst['uri'])) {
                    $uri = (string) $inst['uri'];
                }
                if (is_array($inst) && !empty($inst['instances']) && is_array($inst['instances'])) {
                    $rawInstances = $inst['instances'];
                }
            } catch (\Throwable $e) {
                $diag['instance_error_after_create'] = $e->getMessage();
            }
        }
    } else {
        $diag['create_error'] = $createError;
    }

    $diag['uri_present'] = ($uri !== null && $uri !== '');
    if ($uri !== null && $uri !== '') {
        // убираем «$название подключения» из ключа
        $uri = (string) $_olcrtcCleanUriFn($uri);
    }

    // ---- три ключа: подписи + чистые URI ----
    $slotMeta = [];
    try {
        foreach (\Plugin\OlcRTC\Services\OlcRTCManagerClient::slots() as $s) {
            $slotMeta[(int) $s['slot']] = $s;
        }
    } catch (\Throwable $e) {
    }
    $keys = [];
    foreach ($rawInstances as $it) {
        $slot = (int) ($it['slot'] ?? 0);
        $kUri = (string) ($it['uri'] ?? '');
        if ($kUri !== '') {
            $kUri = (string) $_olcrtcCleanUriFn($kUri);
        }
        $keys[$slot] = [
            'slot'  => $slot,
            'host'  => (string) ($slotMeta[$slot]['host'] ?? ($it['host'] ?? '')),
            'label' => (string) ($slotMeta[$slot]['label'] ?? ('Ключ #' . ($slot + 1))),
            'uri'   => $kUri,
            'ready' => ($kUri !== ''),
        ];
    }
    foreach ($slotMeta as $slot => $s) {
        if (!isset($keys[$slot])) {
            $keys[$slot] = [
                'slot'  => $slot,
                'host'  => (string) $s['host'],
                'label' => (string) $s['label'],
                'uri'   => '',
                'ready' => false,
            ];
        }
    }
    ksort($keys);
    $keys = array_values($keys);
    $keysReady = 0;
    foreach ($keys as $k) {
        if (!empty($k['ready'])) $keysReady++;
    }

    if ($user->isActive()) {
        if ($isTrial) {
            $banner = '🎁 У вас БЕСПЛАТНЫЙ тестовый доступ OlcRTC на 3 дня — ' . $leftText . ' '
                    . 'Действует до <b>' . $expText . '</b> (МСК), карта не нужна.'
                    . ($expiringSoon ? '<br><b>⚠️ Тест скоро закончится — оформите тариф, чтобы VPN не отключился.</b>' : '');
        } else {
            $banner = '✅ Подписка активна' . ($planTitle !== '' ? ' («' . e($planTitle) . '»)' : '') . ' — ' . $leftText . ' '
                    . 'Действует до <b>' . $expText . '</b> (МСК).';
            if ($expiringSoon) {
                $banner .= '<br><b>⚠️ Подписка истекает менее чем через сутки — продлите в разделе «Купить подписку».</b>';
            }
        }
    } else {
        $banner = '⛔ Активной подписки нет — ключ работать не будет. Оформите тариф или активируйте бесплатный тест на 3 дня.';
    }

    if ($uri !== null && $uri !== '') {
        $banner .= '<br>🔑 Ключи выданы (' . $keysReady . ' из ' . count($keys) . '). Нажмите «📋 СКОПИРОВАТЬ» у нужного ключа → вставьте в клиент OlcBox / owenclave → Connect.';
        $cta    = '📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)';
        $hint   = 'Ключ формата olcrtc://jitsi?datachannel@… уникален для вашего аккаунта. Не передавайте его другим. Если один хост не подключается — попробуйте другой ключ из списка ниже.';
    } else {
        $reason = $diag['create_error'] ?? $diag['instance_error'] ?? $diag['instance_error_after_create'] ?? null;
        if (!$reason && isset($diag['manager_healthz_http']) && !is_int($diag['manager_healthz_http'])) {
            $reason = 'olcrtc-manager недоступен по адресу ' . $conf['manager_url'] . ' (' . $diag['manager_healthz_http'] . ')';
        }
        $banner .= '<br>⏳ КЛЮЧ ГОТОВИТСЯ. Нажмите «🔄 Пересоздать ключ» и подождите 1 минуту.';
        if ($reason) {
            $banner .= '<br><small style="opacity:.85">Причина: ' . e($reason) . '</small>';
        } else {
            $banner .= '<br><small style="opacity:.85">Инстанс ещё не выдан olcrtc-manager. Если так больше 5 минут — создайте Тикет.</small>';
        }
        $cta  = '🔄 ПЕРЕСОЗДАТЬ КЛЮЧ (получить быстрее)';
        $hint = 'Ключ появится здесь автоматически. Пока его нет — можно проверить диагностику: ' . url('/api/olcrtc/diag?token=' . $user->token);
    }

    return response()->json([
        'status'           => 'ok',
        'user_id'          => $user->id,
        'uri'              => $uri,
        'keys'             => $keys,
        'keys_ready'       => $keysReady,
        'banner'           => $banner,
        'primary_cta'      => $cta,
        'uri_copy_hint'    => $hint,
        'uri_warning'      => '',
        'subscribe_url'    => $subUrl,
        'yaml_url'         => $yamlUrl,
        'client_downloads' => $clients,
        'plan_id'          => $planId,
        'plan_title'       => $planTitle,
        'plan_type'        => $isTrial ? 'trial' : ($user->isActive() ? 'paid' : 'none'),
        'expired_at'       => $user->expired_at,
        'expired_at_text'  => $expText,
        'hours_left'       => $hoursLeft,
        'days_left'        => $daysLeft,
        'time_left_text'   => $leftText,
        'expiring_soon'    => $expiringSoon,
        'is_active'        => $user->isActive(),
        'has_active_plan'  => $user->isActive(),
        'purchase_url'     => url('/#/plan'),
        'orders_url'       => url('/#/order'),
        'create_error'     => $createError,
        'diag'             => $diag,
    ]);
})->middleware('web');

/*
| Витрина оплаты для личного кабинета («Купить ключ»).
| Отдаёт ровно то, что настроено в админке: включённые способы оплаты и
| тарифы с ценами по периодам. Секретов (номера кошельков, API-ключи) не
| отдаём — только отображаемые названия, чтобы пользователь понимал, как платить.
*/
Route::get('/api/olcrtc/pay-info', function (Request $request) {
    // ---- способы оплаты, включённые в админке ----
    $hookMeta = [];
    try {
        $hookMeta = \App\Services\Plugin\HookManager::filter('available_payment_methods', []);
    } catch (\Throwable $e) {
    }

    $methods = [];
    try {
        foreach (\App\Models\Payment::where('enable', 1)->orderBy('sort')->get() as $p) {
            $code = (string) $p->payment;
            $meta = is_array($hookMeta[$code] ?? null) ? $hookMeta[$code] : [];
            $name = trim((string) ($p->name ?? ''));
            if ($name === '') {
                $name = (string) ($meta['show_name'] ?? ($meta['name'] ?? $code));
            }
            $icon = trim((string) ($p->icon ?? ''));
            if ($icon === '') {
                $icon = (string) ($meta['icon'] ?? '💳');
            }
            $methods[] = [
                'id' => (int) $p->id,
                'code' => $code,
                'name' => $name,
                'icon' => $icon,
                'handling_fee_fixed' => (int) ($p->handling_fee_fixed ?? 0),
                'handling_fee_percent' => (float) ($p->handling_fee_percent ?? 0),
            ];
        }
    } catch (\Throwable $e) {
    }

    // ---- тарифы с ценами ----
    $periodLabels = [
        'monthly'       => '1 месяц',
        'quarterly'     => '3 месяца',
        'half_yearly'   => '6 месяцев',
        'yearly'        => '12 месяцев',
        'two_yearly'    => '24 месяца',
        'three_yearly'  => '36 месяцев',
        'onetime'       => 'Разово',
        'reset_traffic' => 'Сброс трафика',
    ];

    $plans = [];
    try {
        foreach (\App\Models\Plan::where('show', 1)->orderBy('sort')->get() as $plan) {
            $prices = [];
            // В v2_plan цена хранится УЖЕ в рублях (OrderService умножает её на 100,
            // получая копейки для заказа), поэтому делить на 100 не нужно.
            foreach ($plan->getPriceList() as $period => $row) {
                $priceRub = (float) ($row['price'] ?? 0);
                if ($priceRub <= 0) {
                    continue;
                }
                $decimals = ($priceRub == floor($priceRub)) ? 0 : 2;
                $prices[] = [
                    'period'     => (string) $period,
                    'label'      => $periodLabels[$period] ?? (string) ($row['period']['name'] ?? $period),
                    'price'      => $priceRub,
                    'price_text' => number_format($priceRub, $decimals, '.', ' ') . ' ₽',
                    'days'       => (int) ($row['period']['days'] ?? 0),
                ];
            }
            if ($prices === []) {
                continue;
            }
            // В v2_plan лимит трафика тоже уже в ГБ (UserService умножает его на 1073741824).
            $limitGb = (float) ($plan->transfer_enable ?? 0);
            $plans[] = [
                'id'             => (int) $plan->id,
                'name'           => (string) $plan->name,
                'prices'         => $prices,
                'transfer_limit' => ($limitGb > 0)
                    ? (($limitGb == floor($limitGb)) ? (int) $limitGb : $limitGb) . ' ГБ'
                    : 'безлимит',
            ];
        }
    } catch (\Throwable $e) {
    }

    return response()->json([
        'status'     => 'ok',
        'methods'    => $methods,
        'plans'      => $plans,
        'plan_url'   => url('/#/plan'),
        'orders_url' => url('/#/order'),
    ]);
})->middleware('web');

/*
| Downloadable OlcRTC client.yaml via the user's personal token (the widget's
| "Скачать client.yaml" link opens in a new tab, so it cannot send a Bearer
| header — the token in the query is the same mechanism as /olcrtc/sub).
*/
Route::get('/api/olcrtc/yaml', function (Request $request) use ($_olcrtcUserFn, $_olcrtcMgrFn) {
    try {
        $user = $_olcrtcUserFn($request);
        if (!$user) {
            $token = (string) $request->input('token', '');
            if ($token !== '') {
                $user = \App\Models\User::where('token', $token)->first();
            }
        }
        if (!$user) {
            return response("no user", 401)->header('Content-Type', 'text/plain; charset=utf-8');
        }
        if ($user->banned || !$user->isActive()) {
            return response("# subscription disabled\n", 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $yaml = $_olcrtcMgrFn()->getUserYaml((int) $user->id);
        if ($yaml === null || $yaml === '') {
            return response("# no active olcrtc instance\n", 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }
        return response($yaml, 200, [
            'Content-Type'        => 'application/yaml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="olcrtc-client.yaml"',
        ]);
    } catch (\Throwable $e) {
        return response("# olcrtc manager error: " . $e->getMessage() . "\n", 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
})->middleware('web');

/*
| Plain-text OlcRTC subscription (sub.md: "#name" + olcrtc:// URI) served by the
| core app, NOT by the plugin — this is the URL the SPA "copy subscription link"
| button and the widget hand out, so it must stay reachable even when the plugin
| row is disabled.  Same format as Plugin\OlcRTC\Controllers\OlcRTCController::subscribe().
*/
$_olcrtcSubFn = function (Request $request) use ($_olcrtcUserFn, $_olcrtcMgrFn, $_olcrtcCleanUriFn) {
    try {
        $user = $_olcrtcUserFn($request);
        if (!$user) {
            // token comes either as ?token=… or as the {token} route parameter (/s/{token})
            $token = (string) ($request->input('token') ?: $request->route('token') ?: '');
            if ($token !== '') {
                $user = \App\Models\User::where('token', $token)->first();
            }
        }
        if (!$user) {
            return response("# user not found\n", 404)->header('Content-Type', 'text/plain; charset=utf-8');
        }
        if ($user->banned || !$user->isActive()) {
            return response("# subscription disabled\n# banned or expired\n", 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $txt = $_olcrtcMgrFn()->getUserSub((int) $user->id);
        if ($txt === null || $txt === '') {
            $txt = "# no active olcrtc instance\n";
        }
        // olcrtc://…@https://host/room#<key>$<метка> — метку «$<метка>» сохраняем
        // (см. $_olcrtcCleanUriFn): так ключ в подписке точно совпадает по виду
        // с рабочим эталоном из гайда. Нормализуются только переносы/пробелы.
        $txt = $_olcrtcCleanUriFn($txt);
        return response($txt, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    } catch (\Throwable $e) {
        return response("# olcrtc manager error: " . $e->getMessage() . "\n", 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
};

Route::get('/api/olcrtc/sub', $_olcrtcSubFn)->middleware('web');

/*
| Plain-text diagnostics for the customer widget.  Returns the effective manager
| connection settings (API key masked), liveness and the last manager error —
| open it in a browser (with ?token=<user token>) and paste the JSON into a
| support ticket.
*/
Route::get('/api/olcrtc/diag', function (Request $request) use ($_olcrtcUserFn, $_olcrtcMgrConfFn, $_olcrtcMgrFn) {
    $user = $_olcrtcUserFn($request);
    if (!$user) {
        $token = (string) $request->input('token', '');
        if ($token !== '') {
            $user = \App\Models\User::where('token', $token)->first();
        }
    }
    $conf = $_olcrtcMgrConfFn();
    $out = [
        'time'              => date('c'),
        'user_resolved'     => (bool) $user,
        'user_id'           => $user->id ?? null,
        'is_active'         => $user ? $user->isActive() : null,
        'plan_id'           => $user->plan_id ?? null,
        'expired_at'        => $user->expired_at ?? null,
        'manager_url'       => $conf['manager_url'],
        'api_key_length'    => strlen((string) $conf['manager_api_key']),
        'provider'          => $conf['default_provider'],
        'transport'         => $conf['default_transport'],
        'dns'               => $conf['default_dns'],
    ];
    try {
        $out['plugin_enabled'] = (bool) \App\Models\Plugin::where('code', 'olc_rtc')->value('is_enabled');
    } catch (\Throwable $e) {
        $out['plugin_enabled'] = 'ERR ' . $e->getMessage();
    }
    try {
        $h = \Illuminate\Support\Facades\Http::timeout(5)->acceptJson()
            ->get(rtrim((string) $conf['manager_url'], '/') . '/healthz');
        $out['healthz_http'] = $h->status();
        $out['healthz_body'] = mb_substr((string) $h->body(), 0, 200);
    } catch (\Throwable $e) {
        $out['healthz_http'] = 'ERR ' . $e->getMessage();
    }
    // Key probe: proves whether the key kept in the DB/ENV is accepted by the manager.
    // 401 here means the plugin/admin key differs from the manager's OLCRMGR_API_KEY —
    // restart olcrtc-manager after fixing .env, or re-save the plugin settings.
    $cand1 = (string) $conf['manager_api_key'];
    $cand2 = (string) env('OLCRMGR_API_KEY', '');
    $out['api_key_env_length'] = strlen($cand2);
    $out['api_key_db_eq_env']  = ($cand1 !== '' && $cand2 !== '') ? ($cand1 === $cand2) : null;
    foreach (['db' => $cand1, 'env' => $cand2] as $src => $k) {
        if ($k === '') {
            $out['auth_' . $src] = 'not set';
            continue;
        }
        try {
            $p = \Illuminate\Support\Facades\Http::withToken($k)->timeout(5)->acceptJson()
                ->get(rtrim((string) $conf['manager_url'], '/') . '/api/v1/instances');
            $out['auth_' . $src] = $p->status() === 401 ? 'HTTP 401 unauthorized' : ('HTTP ' . $p->status() . ' OK');
        } catch (\Throwable $e) {
            $out['auth_' . $src] = 'ERR ' . $e->getMessage();
        }
    }
    if ((string) $out['auth_db'] === 'HTTP 401 unauthorized' && (string) ($out['auth_env'] ?? '') === 'HTTP 401 unauthorized') {
        $out['hint'] = 'Ключ Xboard не совпадает с OLCRMGR_API_KEY у olcrtc-manager. На VPS: '
            . 'echo OLCRMGR_API_KEY=$(grep "^OLCRMGR_API_KEY=" .env | cut -d= -f2) и docker compose exec olcrtc-manager printenv OLCRMGR_API_KEY, '
            . 'затем docker compose up -d --force-recreate olcrtc-manager xboard';
    }
    if ($user) {
        try {
            $mgr = $_olcrtcMgrFn();
            $inst = $mgr->getUserInstance((int) $user->id);
            $out['instance'] = is_array($inst) && !empty($inst['instance']) ? 'present' : 'absent';
            $out['uri_present'] = !empty($inst['uri']);
        } catch (\Throwable $e) {
            $out['instance_error'] = $e->getMessage();
        }
    }
    return response()->json($out, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('web');

Route::post('/api/olcrtc/claim-trial', function (Request $request) use ($_olcrtcUserFn, $_olcrtcTrialPlanFn) {
    $user = $_olcrtcUserFn($request);
    if (!$user) {
        return response()->json([
            'status'  => 'guest',
            'success' => false,
            'message' => 'Требуется вход в личный кабинет для получения тестовой подписки.'
        ], 401);
    }
    try {
        $user = \App\Models\User::find($user->id);
        if (!$user) {
            return response()->json(['status'=>'fail','success'=>false,'message'=>'Пользователь не найден (cookie?) — перелогиньтесь.'],400);
        }
        $settingKey = 'olcrtc_trial_claimed_'.((int)$user->id);
        $already = (int)\App\Models\Setting::where('name', $settingKey)->value('value');
        if ($already >= 1) {
            $msg = '⚠️ Тестовая подписка на 3 дня (72 часа) уже была получена на этот аккаунт ранее. '
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
        $hours = max(1, (int)admin_setting('try_out_hour', 72));
        // Self-healing: если сохранённый try_out_plan_id «битый» (указывает на
        // несуществующий план), резолвер найдёт реальный тариф («Базовый» по
        // имени либо первый доступный) и починит настройку.
        $plan = $_olcrtcTrialPlanFn();
        if (!$plan) {
            return response()->json([
                'status'  => 'fail',
                'success' => false,
                'message' => 'Тестовый тариф не найден: в панели нет ни одного тарифного плана. Перезапустите контейнер xboard (тарифы засеются автоматически) или создайте тариф в админке и попробуйте снова.',
            ], 500);
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
            $mgr = $_olcrtcMgrFn();
        } catch (\Throwable $e) {
            $mgr = null;
        }
        $preCreateError = null;
        if ($mgr !== null) {
            try {
                $mgr->provisionAllSlots($user->id, (int)$user->expired_at);
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

        // Точный момент окончания теста — чтобы после покупки тарифа подписка
        // больше НЕ помечалась как «бесплатный тест 3 дня».
        try {
            $expKey = 'olcrtc_trial_expires_' . (int) $user->id;
            $rowExp = \App\Models\Setting::where('name', $expKey)->first();
            if (!$rowExp) {
                $rowExp = new \App\Models\Setting();
                $rowExp->name = $expKey;
                $rowExp->created_at = time();
            }
            $rowExp->value = (string) (int) $user->expired_at;
            $rowExp->updated_at = time();
            $rowExp->save();
        } catch (\Throwable $e) {}

        $freshUser = \App\Models\User::find($user->id);
        $expText = date('d.m.Y H:i', (int)($freshUser->expired_at ?? time()));
        $mgrOk   = $preCreateError === null;
        return response()->json([
            'status'   => 'success',
            'success'  => true,
            'trial_hours' => $hours,
            'plan_id'  => (int) ($plan->id ?? 0),
            'plan_title' => (string)($plan->name ?? $plan->title ?? 'Тест (Базовый)'),
            'expired_at_text' => $expText,
            'message'  => '✅ Тестовая подписка OlcRTC на '.$hours.' ч. ('.round($hours/24).' дн.) АКТИВИРОВАНА! Действительна до '.$expText.'. '
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

Route::post('/api/olcrtc/recreate', function (Request $request) use ($_olcrtcUserFn, $_olcrtcMgrFn) {
    $user = $_olcrtcUserFn($request);
    if (!$user) {
        return response()->json(['status'=>'guest','success'=>false,'message'=>'Требуется вход в ЛК.'],401);
    }
    try {
        $user = \App\Models\User::find($user->id);
        if (!$user) return response()->json(['status'=>'fail','message'=>'Пользователь не найден — перелогиньтесь.'],400);
        if ($user->banned) return response()->json(['status'=>'fail','message'=>'Аккаунт заблокирован.'],403);
        if (!$user->isActive()) return response()->json([
            'status'=>'fail','success'=>false,
            'message'=>'⚠️ У вас ещё нет активной подписки. Купите тариф в разделе «Оплата» или активируйте бесплатный тест на 3 дня (72 часа).'
        ], 409);

        // Тот же self-healing клиент, что и в виджете: ключ проверяется менеджером
        // и при необходимости берётся из ENV контейнера (иначе recreate падал с HTTP 401).
        $mgr = null;
        try { $mgr = $_olcrtcMgrFn(); } catch (\Throwable $e) { $mgr = null; }
        $createError = null;
        if ($mgr === null) $createError = 'OlcRTC Manager client not available';
        else {
            try {
                // Кнопка «Пересоздать ключ» — новые комнаты и новые crypto key во всех слотах.
                $mgr->provisionAllSlots($user->id, (int)$user->expired_at, true);
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

Route::get('/api/olcrtc/trial-status', function (Request $request) use ($_olcrtcUserFn, $_olcrtcTrialInfoFn) {
    $user = $_olcrtcUserFn($request);
    if (!$user) {
        return response()->json(['status'=>'guest','claimed'=>false,'active'=>false],200);
    }
    try {
        $user    = \App\Models\User::find($user->id);
        $trial   = $_olcrtcTrialInfoFn($user);
        $isTrial = (bool) $trial['is_trial'];
        $planId  = (int) ($user->plan_id ?? 0);
        return response()->json([
            'status'   => 'ok',
            'claimed'  => (bool) $trial['claimed'],
            'active'   => $user->isActive(),
            'expired_at' => $user->expired_at,
            'expired_at_text' => $trial['exp_text'] !== '' ? $trial['exp_text'] : null,
            'time_left_text'  => $trial['left_text'],
            'hours_left'      => $trial['hours_left'],
            'plan_type'       => $isTrial ? 'trial' : ($user->isActive() ? 'paid' : 'none'),
            'plan_id'    => $planId,
            'try_out_hour' => (int) $trial['trial_hours'],
        ],200);
    } catch (\Throwable $e) {
        return response()->json(['status'=>'fail','claimed'=>false,'active'=>false,'error'=>$e->getMessage()],500);
    }
})->middleware('web');

/*
|--------------------------------------------------------------------------
| ЮMoney: приложение — «Подключить кошелёк» + Redirect/Notification URI.
|--------------------------------------------------------------------------
| Адреса, которые владелец указывает в настройках приложения ЮMoney
| (https://yoomoney.ru/settings/oauth-services → «Доступ к кошельку»):
|   • Redirect URI     → https://<домен>/yoomoney/callback
|   • Notification URI → https://<домен>/yoomoney-webhook
|
| Полный OAuth-поток приложения:
|   1. Владелец открывает https://<домен>/yoomoney/connect — редирект на
|      https://yoomoney.ru/oauth/authorize (шаг «Доступ к кошельку»);
|   2. ЮMoney возвращает его на /yoomoney/callback?code=…&state=<uuid> —
|      код меняется на access_token и сохраняется в настройки шлюза;
|   3. Уведомления об оплате приходят на /yoomoney-webhook (тот же обработчик,
|      что и штатный notify-эндпоинт).
|
| Приём оплаты на кошелёк (Quickpay) работает и БЕЗ шага 1-2 — эти маршруты
| нужны, чтобы приложение ЮMoney было полностью подключено.
*/
Route::get('/yoomoney/connect', function (Request $request) {
    $payment = \App\Models\Payment::where('payment', 'yoomoney')->orderBy('sort')->first();
    if (!$payment) {
        return response('ЮMoney не добавлена в админке: сначала добавьте платёжный метод «ЮMoney (оплата на кошелёк, без ИП)».', 404)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    try {
        $cfg = $payment->makeVisible('config')->config;
        $cfg = is_string($cfg) ? json_decode($cfg, true) : $cfg;
        $gateway = new \Plugin\OlcRTC\Payments\YooMoneyPayment(is_array($cfg) ? $cfg : []);
        return redirect()->away($gateway->authorizeUrl((string) $payment->uuid));
    } catch (\Throwable $e) {
        Log::warning('[OlcRTC-YooMoney] connect error: ' . $e->getMessage());
        return response(
            "Не удалось открыть страницу авторизации ЮMoney.\n\n" . $e->getMessage()
            . "\n\nЗаполните в админке поля 5-6 (client_id и client_secret приложения ЮMoney) и обновите эту страницу.",
            400
        )->header('Content-Type', 'text/plain; charset=utf-8');
    }
});

Route::get('/yoomoney/callback', function (Request $request) {
    $code  = (string) $request->query('code', '');
    $state = (string) $request->query('state', '');
    $order = (string) $request->query('order', '');

    $payment = null;
    if ($state !== '') {
        $payment = \App\Models\Payment::where('payment', 'yoomoney')->where('uuid', $state)->first();
    }
    if (!$payment) {
        $payment = \App\Models\Payment::where('payment', 'yoomoney')->orderBy('sort')->first();
    }

    $connected = false;
    if ($payment && $code !== '') {
        try {
            $cfg = $payment->makeVisible('config')->config;
            $cfg = is_string($cfg) ? json_decode($cfg, true) : $cfg;
            $gateway = new \Plugin\OlcRTC\Payments\YooMoneyPayment(is_array($cfg) ? $cfg : []);
            $token   = $gateway->exchangeCode($code);
            $cfg['access_token']       = (string) ($token['access_token'] ?? '');
            if (!empty($token['refresh_token'])) {
                $cfg['refresh_token'] = (string) $token['refresh_token'];
            }
            $cfg['oauth_connected_at'] = now()->toIso8601String();
            $payment->config = $cfg;
            $payment->save();
            $connected = true;
            Log::info('[OlcRTC-YooMoney] OAuth token saved for payment #' . $payment->id);
        } catch (\Throwable $e) {
            Log::warning('[OlcRTC-YooMoney] OAuth callback error: ' . $e->getMessage());
        }
    }

    // Возврат из платёжного потока — в личный кабинет (в заказ, если он известен).
    if ($order !== '') {
        return redirect('/#/order/' . rawurlencode($order));
    }

    // Сюда попадает владелец кошелька, подключавший приложение («Доступ к кошельку»):
    // показываем ему понятный результат, а не пустую страницу кабинета.
    return response(
        $connected
            ? "✅ Приложение ЮMoney подключено. Токен доступа сохранён — страницу можно закрыть."
            : "⚠️ ЮMoney не вернула код авторизации (или не заданы client_id и client_secret в админке). Повторите подключение: https://<ваш-домен>/yoomoney/connect",
        200,
        ['Content-Type' => 'text/plain; charset=utf-8']
    );
});

Route::match(['get', 'post'], '/yoomoney-webhook', function (Request $request) {
    $params = array_merge($request->query(), $request->post());
    Log::info('[OlcRTC-YooMoney] webhook: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

    foreach (\App\Models\Payment::where('payment', 'yoomoney')->get() as $payment) {
        if (!$payment->enable) {
            continue;
        }
        try {
            // Собираем шлюз напрямую из настроек платёжки: обработчик уведомлений
            // не должен зависеть от того, успел ли загрузиться плагин в этом
            // запросе (иначе подтверждение подписи «тихо» падало с 500 и платёж
            // не зачислялся).
            $cfg = $payment->makeVisible('config')->config;
            $cfg = is_string($cfg) ? json_decode($cfg, true) : $cfg;
            $verify = (new \Plugin\OlcRTC\Payments\YooMoneyPayment(is_array($cfg) ? $cfg : []))->notify($params);
        } catch (\Throwable $e) {
            Log::warning('[OlcRTC-YooMoney] webhook verify error (payment #' . $payment->id . '): ' . $e->getMessage());
            continue;
        }
        if (!is_array($verify) || empty($verify['trade_no'])) {
            continue;
        }
        $tradeNo = (string) $verify['trade_no'];
        $order   = \App\Models\Order::where('trade_no', $tradeNo)->first();
        if ($order && $order->status === \App\Models\Order::STATUS_PENDING) {
            $orderService = new \App\Services\OrderService($order);
            if ($orderService->paid((string) ($verify['callback_no'] ?? $tradeNo))) {
                \App\Services\Plugin\HookManager::call('payment.notify.success', $order);
            }
        }
        return response('OK', 200);
    }

    // Подпись не подтверждена ни одним включённым методом ЮMoney → просим повтор.
    return response('FAIL', 500);
});

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

// Памятка для админа: обновление ядра OlcRTC и панели, диагностика, оплата,
// полезные команды. Открывается по секретному пути админки и только для чтения
// (секретов внутри нет). Ссылка на неё вставляется в сайдбар админки из
// resources/views/admin.blade.php.
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/olcrtc-guide', function () {
    return view('admin-olcrtc-guide', [
        'app_name' => admin_setting('app_name', 'XBoard'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
    ]);
});

// Legacy V2Ray/Clash subscribe URL (/s/{token}) — в OlcRTC-ONLY режиме отдаём
// подписку OlcRTC в том же формате, что и /api/olcrtc/sub, чтобы клиенты,
// импортирующие «стандартную» ссылку подписки, получали рабочий olcrtc:// URI.
Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', $_olcrtcSubFn)
    ->middleware('web')
    ->name('client.subscribe');
