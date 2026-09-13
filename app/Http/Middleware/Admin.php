<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        /** @var User|null $user */
        // Primary: Bearer Sanctum token (mobile/API clients)
        $user = Auth::guard('sanctum')->user();
        // Fallback: same-domain SPA admin browser cookie (web guard session).
        // The API middleware group has no session by default, but some Octane/stateful
        // setups (or older cached auth) may pass a web-guard user via Auth::getUser().
        if (!$user || !$user->is_admin) {
            $fallback = Auth::guard('web')->user();
            if ($fallback && $fallback instanceof User && $fallback->is_admin) {
                $user = $fallback;
            }
        }
        if (!$user || !$user->is_admin) {
            // Last-ditch: if request cookies include the Xboard session and the route exists
            // but is being called statelessly, try setting the user via default driver.
            try {
                $any = Auth::user();
                if ($any && $any instanceof User && $any->is_admin) {
                    $user = $any;
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        if (!$user || !$user->is_admin) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Unauthorized: требуется вход админ-аккаунтом (cookie или Bearer токен)',
                'code' => 401,
            ], 401, ['Content-Type' => 'application/json']);
        }

        return $next($request);
    }
}
