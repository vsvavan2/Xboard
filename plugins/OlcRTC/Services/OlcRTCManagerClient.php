<?php

namespace Plugin\OlcRTC\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент REST API olcrtc-manager.
 * @see olcrtc-manager/internal/api/api.go
 */
class OlcRTCManagerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $defaultProvider,
        private readonly string $defaultTransport,
        private readonly string $defaultDns,
        private readonly string $defaultToken,
    ) {
    }

    /**
     * Создать или обновить olcrtc-инстанс для пользователя.
     */
    public function createOrUpdateInstance(int $userId, int $expiresAt, string $comment = ''): array
    {
        $body = [
            'user_id'    => $userId,
            'provider'   => $this->defaultProvider,
            'transport'  => $this->defaultTransport,
            'dns'        => $this->defaultDns,
            'token'      => $this->defaultToken,
            'expires_at' => $expiresAt,
            'comment'    => $comment,
        ];
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post($this->baseUrl . '/api/v1/instances', $body);
        if (!$resp->successful()) {
            throw new \RuntimeException('olcrtc-manager create failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->json();
    }

    /**
     * Получить инфо о инстансе пользователя + URI, YAML.
     */
    public function getUserInstance(int $userId): ?array
    {
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->get($this->baseUrl . "/api/v1/user/{$userId}");
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('olcrtc-manager get user failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->json();
    }

    /**
     * Остановить инстанс пользователя.
     */
    public function stopUserInstance(int $userId): void
    {
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->delete($this->baseUrl . "/api/v1/user/{$userId}");
        if (!$resp->successful() && $resp->status() !== 404) {
            throw new \RuntimeException('olcrtc-manager stop user failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
    }

    /**
     * Получить olcrtc:// URI для клиентских приложений.
     */
    public function getUserUri(int $userId, string $mimo = ''): ?string
    {
        $query = [];
        if ($mimo !== '') {
            $query['mimo'] = $mimo;
        }
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->get($this->baseUrl . "/api/v1/user/{$userId}/uri", $query);
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('olcrtc-manager uri failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->json('uri');
    }

    /**
     * Получить клиентский YAML.
     */
    public function getUserYaml(int $userId): ?string
    {
        $resp = Http::withToken($this->apiKey)
            ->get($this->baseUrl . "/api/v1/user/{$userId}/yaml");
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('olcrtc-manager yaml failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->body();
    }

    /**
     * Получить подписку в формате sub.md (plain text).
     */
    public function getUserSub(int $userId): ?string
    {
        $resp = Http::withToken($this->apiKey)
            ->get($this->baseUrl . "/api/v1/user/{$userId}/sub");
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            throw new \RuntimeException('olcrtc-manager sub failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->body();
    }

    /**
     * Крон: остановить пользователей у которых подписка истекла.
     */
    public function syncExpiredUsers(): void
    {
        $expired = User::whereNotNull('expired_at')
            ->where('expired_at', '<', time())
            ->where('banned', 0)
            ->get(['id', 'expired_at', 'plan_id']);
        foreach ($expired as $u) {
            if ((int) $u->plan_id === 0 || $u->plan_id === null) {
                continue;
            }
            try {
                $this->stopUserInstance($u->id);
                Log::info('[OlcRTC] stopped expired user ' . $u->id);
            } catch (\Throwable $e) {
                Log::warning('[OlcRTC] stop expired user ' . $u->id . ' failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Крон: убедиться что у всех активных пользователей есть запущенный инстанс с корректным expires_at.
     */
    public function reconcileActiveUsers(): void
    {
        $active = User::whereNotNull('plan_id')
            ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', time()))
            ->where('banned', 0)
            ->get(['id', 'expired_at']);
        foreach ($active as $u) {
            try {
                $exp = $u->expired_at ?? (time() + 365 * 86400);
                $this->createOrUpdateInstance($u->id, (int) $exp, 'reconcile');
            } catch (\Throwable $e) {
                Log::warning('[OlcRTC] reconcile user ' . $u->id . ' failed: ' . $e->getMessage());
            }
        }
    }
}
