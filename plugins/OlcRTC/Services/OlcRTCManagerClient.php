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
     * Self-healing выбор API-ключа. Настройки плагина могут содержать устаревший
     * или пустой ключ (например, .env ротировали после установки, либо в админке
     * сохранили пустое поле), а olcrtc-manager проверяет свой OLCRMGR_API_KEY.
     * Проверяем кандидатов против /api/v1/instances и оставляем рабочий.
     */
    public static function resolveApiKey(string $baseUrl, string $key): string
    {
        $url = rtrim($baseUrl, '/');

        $cands = [];
        foreach ([$key, (string) env('OLCRMGR_API_KEY', '')] as $k) {
            if ((string) $k !== '' && !in_array((string) $k, $cands, true)) {
                $cands[] = (string) $k;
            }
        }
        if (!$cands) {
            return '';
        }
        foreach ($cands as $k) {
            try {
                $resp = Http::withToken($k)->acceptJson()->timeout(4)->get($url . '/api/v1/instances');
                if ($resp->status() !== 401 && $resp->status() !== 403) {
                    return $k;
                }
            } catch (\Throwable $e) {
                // менеджер недоступен — оставляем исходный ключ, ошибку покажет вызывающий код
                return $cands[0];
            }
        }
        return $cands[0];
    }

    /**
     * Резолвинг базового URL olcrtc-manager.
     *
     * Внутри контейнера Xboard адрес `127.0.0.1:8080` НЕДОСТИЖИМ — менеджер живёт
     * в отдельном compose-сервисе `olcrtc-manager`. Поэтому localhost-значения
     * отбраковываются, а если ничего валидного не нашлось — берём имя сервиса
     * из docker-сети. Источники по приоритету: переданный конфиг → строка плагина
     * в БД → ENV контейнера → настройки админки.
     */
    public static function resolveBaseUrl(string $configured = ''): string
    {
        $cands = [$configured];

        try {
            $row = \App\Models\Plugin::where('code', 'olc_rtc')->first();
            if ($row && !empty($row->config)) {
                $cfg = is_array($row->config) ? $row->config : (json_decode((string) $row->config, true) ?: []);
                if (!empty($cfg['manager_url'])) {
                    $cands[] = (string) $cfg['manager_url'];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $cands[] = (string) env('OLCRMGR_URL', '');
        } catch (\Throwable $e) {
        }

        try {
            $cands[] = (string) admin_setting('olcrtc_manager_url', '');
        } catch (\Throwable $e) {
        }

        foreach ($cands as $c) {
            $c = trim((string) $c);
            if ($c === '' || str_contains($c, '127.0.0.1') || str_contains($c, 'localhost')) {
                continue;
            }
            return rtrim($c, '/');
        }

        return 'http://olcrtc-manager:8080';
    }

    /**
     * Слоты ключей: один olcrtc:// ключ на каждый Jitsi-хост.
     *
     * Панель выдаёт пользователю ТРИ ключа одновременно (и в тесте, и после
     * покупки): основной, «обход белых списков мобильных операторов» и
     * резервный. Каждый слот живёт в olcrtc-manager отдельным инстансом.
     *
     * Хосты можно переопределить ENV-переменными (OLCRTC_HOST_PRIMARY,
     * OLCRTC_HOST_MOBILE, OLCRTC_HOST_ALT).
     */
    public static function slots(): array
    {
        $slots = [
            [
                'slot'  => 0,
                'host'  => 'meet.ffmuc.net',
                'label' => 'Основной ключ — ПК, ноутбук и Android (рекомендуется)',
                'env'   => 'OLCRTC_HOST_PRIMARY',
            ],
            [
                'slot'  => 1,
                'host'  => 'meet.egovm.ru',
                'label' => 'Ключ для обхода белых списков мобильных операторов',
                'env'   => 'OLCRTC_HOST_MOBILE',
            ],
            [
                'slot'  => 2,
                'host'  => 'meet.opensuse.org',
                'label' => 'Резервный ключ (если первые два не подключаются)',
                'env'   => 'OLCRTC_HOST_ALT',
            ],
        ];
        foreach ($slots as &$s) {
            try {
                $override = trim((string) env($s['env'], ''));
            } catch (\Throwable $e) {
                $override = '';
            }
            if ($override !== '') {
                $s['host'] = $override;
            }
            $s['host'] = preg_replace('#^https?://#i', '', rtrim($s['host'], '/'));
        }
        unset($s);
        return $slots;
    }

    /**
     * Создать или обновить olcrtc-инстанс для пользователя.
     *
     * $slot / $host позволяют держать несколько ключей на одного пользователя
     * (по одному на Jitsi-хост). Значения по умолчанию сохраняют прежнее
     * поведение «один ключ / слот 0».
     */
    public function createOrUpdateInstance(int $userId, int $expiresAt, string $comment = 'olc', bool $recreate = false, int $slot = 0, string $host = ''): array
    {
        $body = [
            'user_id'    => $userId,
            'provider'   => $this->defaultProvider,
            'transport'  => $this->defaultTransport,
            'dns'        => $this->defaultDns,
            'token'      => $this->defaultToken,
            'expires_at' => $expiresAt,
            'slot'       => $slot,
            // Comment попадает в ключ как хвост `$<MIMO>`. По соглашению olcrtc
            // (docs/uri) это свободный клиентский комментарий, но выдаваемый ключ
            // должен иметь стабильный вид `...$olc`, поэтому служебные строки
            // (reconcile/trial/auto-seed…) здесь больше не подставляются.
            'comment'    => $comment,
        ];
        if ($host !== '') {
            $body['host'] = $host;
        }
        if ($recreate) {
            // Заставляем менеджер выдать новую комнату и новый crypto key.
            $body['recreate'] = true;
        }
        // Таймауты обязательны: этот метод вызывается синхронно из хука
        // `user.register.after`, т.е. прямо внутри HTTP-запроса регистрации.
        // Без ограничения зависание olcrtc-manager (например, недоступный Jitsi
        // или отсутствующий бинарник olcrtc) держало бы регистрацию до 30 секунд
        // и дольше — прокси/браузер обрывали бы запрос, отдавая пустой ответ,
        // который фронтенд ЛК показывает как «未知错误». Ограничиваем вызов и
        // отдаём ошибку вызывающему коду, где она уже ловится try/catch.
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
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
        Log::info('[OlcRTC] getUserInstance called', ['user_id' => $userId, 'base_url' => $this->baseUrl]);
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(10)
            ->get($this->baseUrl . "/api/v1/user/{$userId}");
        Log::info('[OlcRTC] getUserInstance response', ['status' => $resp->status(), 'body' => $resp->body()]);
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            Log::error('[OlcRTC] getUserInstance failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('olcrtc-manager get user failed (HTTP ' . $resp->status() . '): ' . $resp->body());
        }
        return $resp->json();
    }

    /**
     * Выдать/обновить ключи во ВСЕХ слотах (все Jitsi-хосты) для пользователя.
     *
     * Вызывается при регистрации (тест) и после оплаты (покупка), чтобы
     * пользователь сразу получил три ключа. Ошибка одного хоста не мешает
     * остальным.
     *
     * @return array{ok:int,failed:array<string,string>}
     */
    public function provisionAllSlots(int $userId, int $expiresAt, bool $recreate = false): array
    {
        $ok = 0;
        $failed = [];
        foreach (self::slots() as $s) {
            try {
                $this->createOrUpdateInstance(
                    $userId,
                    $expiresAt,
                    'olc',
                    $recreate,
                    (int) $s['slot'],
                    (string) $s['host']
                );
                $ok++;
            } catch (\Throwable $e) {
                $failed[(string) $s['host']] = $e->getMessage();
                Log::warning('[OlcRTC] slot ' . $s['slot'] . ' (' . $s['host'] . ') provision failed for user ' . $userId . ': ' . $e->getMessage());
            }
        }
        return ['ok' => $ok, 'failed' => $failed];
    }

    /**
     * Все инстансы пользователя (по слотам) с uri/yaml.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getUserInstances(int $userId): array
    {
        try {
            $inst = $this->getUserInstance($userId);
        } catch (\Throwable $e) {
            Log::warning('[OlcRTC] getUserInstances failed for user ' . $userId . ': ' . $e->getMessage());
            return [];
        }
        if (!is_array($inst)) {
            return [];
        }
        $list = $inst['instances'] ?? [];
        return is_array($list) ? $list : [];
    }

    /**
     * Остановить инстанс пользователя.
     */
    public function stopUserInstance(int $userId): void
    {
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
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
        Log::info('[OlcRTC] getUserUri called', ['user_id' => $userId, 'mimo' => $mimo]);
        $query = [];
        if ($mimo !== '') {
            $query['mimo'] = $mimo;
        }
        $resp = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(10)
            ->get($this->baseUrl . "/api/v1/user/{$userId}/uri", $query);
        Log::info('[OlcRTC] getUserUri response', ['status' => $resp->status(), 'body' => $resp->body()]);
        if ($resp->status() === 404) {
            return null;
        }
        if (!$resp->successful()) {
            Log::error('[OlcRTC] getUserUri failed', ['status' => $resp->status(), 'body' => $resp->body()]);
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
            ->connectTimeout(3)
            ->timeout(10)
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
            ->connectTimeout(3)
            ->timeout(10)
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
     * Проверить здоровье olcrtc-manager (healthcheck)
     */
    public function healthCheck(): bool
    {
        try {
            $resp = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(5)
                ->get($this->baseUrl . '/healthz');
            $ok = $resp->successful() && $resp->json()['status'] === 'ok';
            Log::info('[OlcRTC] healthcheck', ['ok' => $ok, 'status' => $resp->status()]);
            return $ok;
        } catch (\Throwable $e) {
            Log::error('[OlcRTC] healthcheck failed', ['error' => $e->getMessage()]);
            return false;
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
                $this->provisionAllSlots((int) $u->id, (int) $exp);
            } catch (\Throwable $e) {
                Log::warning('[OlcRTC] reconcile user ' . $u->id . ' failed: ' . $e->getMessage());
            }
        }
    }
}
