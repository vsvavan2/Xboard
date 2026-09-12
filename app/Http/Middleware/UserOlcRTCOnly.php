<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserOlcRTCOnly
{
    public function handle($request, Closure $next)
    {
        if ((int)admin_setting('subscribe_disabled', 0) !== 1) {
            return $next($request);
        }

        $key = 'olcrtc_user_block_'.$request->ip();
        if (!Cache::has($key)) {
            Cache::put($key, 1, 86400);
            Log::warning('OlcRTC-ONLY USER BLOCK: '.$request->ip().' '.$request->path());
        }

        return response()->json([
            'error'   => true,
            'message' => 'Режим OlcRTC-ONLY. Подписки V2Ray/Clash/SingBox отключены — используйте раздел «Моя подписка» для получения OlcRTC ключа.'
        ], 404);
    }
}
