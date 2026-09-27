<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminOlcRTCOnly
{
    // Endpoint'ы которые должны быть заблокированы в OlcRTC-ONLY режиме
    protected $blockedPaths = [
        'server/',
        'node/',
    ];

    // Исключения - endpoint'ы которые НЕ блокируются даже если совпадают с blockedPaths
    protected $allowedPaths = [
        'server/group',
    ];

    public function handle($request, Closure $next)
    {
        if ((int)admin_setting('olcrtc_only_mode', 0) !== 1) {
            return $next($request);
        }

        $path = $request->path();
        
        // Сначала проверяем исключения
        foreach ($this->allowedPaths as $allowedPath) {
            if (str_contains($path, $allowedPath)) {
                return $next($request);
            }
        }
        
        // Проверяем, начинается ли путь с одного из заблокированных префиксов
        $shouldBlock = false;
        foreach ($this->blockedPaths as $blockedPath) {
            if (str_starts_with($path, $blockedPath)) {
                $shouldBlock = true;
                break;
            }
        }

        // Если не относится к заблокированным - пропускаем
        if (!$shouldBlock) {
            return $next($request);
        }

        $key = 'olcrtc_admin_block_'.$request->ip();
        if (!Cache::has($key)) {
            Cache::put($key, 1, 86400);
            Log::warning('OlcRTC-ONLY ADMIN BLOCK: '.$request->ip().' '.$request->path());
        }

        return response()->json([
            'error'   => true,
            'message' => 'Режим OlcRTC-ONLY. Управление узлами V2Ray/Xray/SS отключено — проект продаёт только OlcRTC VPN.'
        ], 403);
    }
}
