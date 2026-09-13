<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserOlcRTCOnly
{
    public function handle($request, Closure $next)
    {
        try {
            $disabled = 0;
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('v2_settings')) {
                    try {
                        $row = \Illuminate\Support\Facades\DB::table('v2_settings')
                            ->where('name', 'subscribe_disabled')
                            ->limit(1)
                            ->value('value');
                        $val = (string)$row;
                        if ($val !== '' && $val !== null) {
                            $disabled = (int)$val;
                        }
                    } catch (\Throwable $eDB) {
                        $disabled = 0;
                    }
                }
            } catch (\Throwable $eSchema) {
                $disabled = 0;
            }
            if ($disabled === 0) {
                try {
                    $v1 = (int)admin_setting('subscribe_disabled', 0);
                    if ($v1 === 1) $disabled = 1;
                } catch (\Throwable $eHelper) {
                }
            }
            if ($disabled !== 1) {
                return $next($request);
            }

            $path = $request->path();
            $lower = strtolower($path);
            $isLegacyRoute = (
                stripos($lower, '/getsubscribe') !== false ||
                stripos($lower, 'subscribe/get') !== false ||
                stripos($lower, 'server/fetch') !== false ||
                stripos($lower, 'serverfetch') !== false ||
                stripos($lower, '/subscribe_url') !== false ||
                stripos($lower, '/user/') !== false && (
                    strpos($lower, 'subscri') !== false ||
                    strpos($lower, 'server/') !== false
                ) ||
                preg_match('#(^|/)(api/v\d+/user|user|passport/user|client)/[^/]*(getsubscribe|subscrib|serverfetch|server/|subscribtion)#i', $lower)
            );
            if (!$isLegacyRoute) {
                return $next($request);
            }

            $key = 'olcrtc_user_block_' . $request->ip();
            try {
                if (!Cache::has($key)) {
                    Cache::put($key, 1, 86400);
                    Log::warning('OlcRTC-ONLY USER BLOCK LEGACY: ' . $request->ip() . ' ' . $path);
                }
            } catch (\Throwable $e) {
            }

            $ua = (string)($request->header('User-Agent') ?? '');
            $isAjax = (
                strcasecmp((string)($request->header('X-Requested-With') ?? ''), 'XMLHttpRequest') === 0 ||
                stripos((string)($request->header('Accept') ?? ''), 'application/json') !== false ||
                $request->expectsJson() ||
                ($request->isJson()) ||
                (
                    preg_match('/Umi|AntDesign|Xboard|axios|fetch|okhttp|curl|Postman|Mozilla/i', $ua) &&
                    !preg_match('/Clash|sing-box|Surge|Loon|Stash|Shadowrocket|Quantumult|V2Ray|v2rayN|NekoBox/i', $ua)
                )
            );

            $successStub = [
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
                    'olcrtc_only_banner' => '💡 Работаем ТОЛЬКО с OlcRTC VPN (WebRTC). V2Ray/Clash/SingBox отключены. '
                                          . 'Откройте раздел «ЛК → Моя подписка» — там ваш OlcRTC-ключ '
                                          . 'olcrtc://jitsi?datachannel@… и клиенты OlcBox / owenclave.',
                ],
                'message' => 'Режим OlcRTC-ONLY. Подписки V2Ray/Clash/SingBox отключены — '
                           . 'используйте раздел «Моя подписка» для получения OlcRTC ключа.',
            ];

            if (stripos($lower, '/server/fetch') !== false || stripos($lower, 'serverfetch') !== false) {
                if ($isAjax) {
                    return response()->json([
                        'data' => [],
                        'message' => $successStub['data']['olcrtc_only_banner'],
                    ], 200)->header('ETag', '"olcrtc-only-empty-servers"');
                }
            }

            if ($isAjax) {
                return response()->json($successStub, 200);
            }

            $content = "# OlcRTC-only mode — legacy subscribe format disabled.\n"
                     . "# Этот проект выдает ТОЛЬКО OlcRTC VPN ключи (WebRTC).\n"
                     . "# Откройте в браузере: " . url('/#/user/subscription') . " — скопируйте ключ olcrtc://jitsi?datachannel@…\n"
                     . "# Клиенты: OlcBox (ПК) / owenclave (Android) — инструкции там же.\n";
            return response($content, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        } catch (\Throwable $e) {
            try { Log::warning('OlcRTC middleware exception: ' . $e->getMessage()); } catch (\Throwable $e2) {}
            return $next($request);
        }
    }
}
