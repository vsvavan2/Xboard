<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminOlcRTCOnly
{
    // Endpoint'ы которые должны быть заблокированы в OlcRTC-ONLY режиме
    protected $blockedPaths = [
        'server',
        'node',
        'server/group',
    ];

    public function handle($request, Closure $next)
    {
        if ((int)admin_setting('olcrtc_only_mode', 0) !== 1) {
            return $next($request);
        }

        $path = $request->path();
        
        // Проверяем, относится ли запрос к заблокированным endpoint'ам
        $shouldBlock = false;
        foreach ($this->blockedPaths as $blockedPath) {
            if (str_contains($path, $blockedPath)) {
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
