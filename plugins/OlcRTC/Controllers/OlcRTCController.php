<?php

namespace Plugin\OlcRTC\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\User;
use Illuminate\Http\Request;
use Plugin\OlcRTC\Services\OlcRTCManagerClient;

class OlcRTCController extends PluginController
{
    /**
     * GET /api/v1/user/olcrtc — информация о подписке, URI, YAML и ссылка на подписку в личном кабинете
     *
     * ЛОГИКА ВЫДАЧИ КЛЮЧА (ГАРАНТИРОВАННАЯ):
     *   • Если у пользователя ЕСТЬ активный Instance в OlcRTC manager — возвращаем его URI.
     *   • Если Instance ЕЩЁ НЕ СОЗДАН (первый вход / только купил подписку / триал 6ч) —
     *     создаём его на лету POST /api/v1/user/:id и повторно читаем URI.
     *   • Даже если user->isActive() = false (триал истёк / не оплатил) — мы всё равно
     *     пытаемся выдать URI, чтобы в кабинете пользователь увидел «истёк» вместо пустоты.
     */
    public function info(Request $request)
    {
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([401, 'Unauthorized']);
        }
        try {
            $client = $this->client();

            // --- 1) Пытаемся прочитать существующий инстанс ---
            try {
                $data = $client->getUserInstance($user->id);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'no such')) {
                    $data = null;
                } else {
                    throw $e;
                }
            }

            // --- 2) Если инстанса нет (404) — СОЗДАЁМ НА ЛЕТУ ---
            if (empty($data['instance']) || !is_array($data)) {
                try {
                    $expTs = $user->expired_at
                        ? (is_numeric($user->expired_at) ? (int) $user->expired_at : strtotime($user->expired_at))
                        : (time() + 6 * 3600);
                    if (!$expTs || $expTs <= 0) $expTs = time() + 6 * 3600;
                    $client->createOrUpdateInstance($user->id, $expTs, 'auto-seed-' . date('Ymd'));
                    // после создания — перечитываем fresh URI
                    try {
                        $data = $client->getUserInstance($user->id);
                    } catch (\Throwable $e) {
                        $data = ['instance' => null, 'created_online' => true];
                    }
                } catch (\Throwable $e) {
                    // Даже если создать не смогли — продолжаем вернуть клиентам ссылки,
                    // чтобы фронт не показывал пустой экран.
                    $data = ['instance' => null, 'create_error' => $e->getMessage()];
                }
            }

            $uri  = null;
            try { $uri  = $client->getUserUri($user->id); } catch (\Throwable $e) { $uri = null; }
            $yaml = null;
            try { $yaml = $client->getUserYaml($user->id); } catch (\Throwable $e) { $yaml = null; }

            $subUrl  = url('/api/v1/user/olcrtc/sub?token=' . $user->token);
            $yamlUrl = url('/api/v1/user/olcrtc/yaml');

            $clients = [
                [
                    'platform' => 'Windows / macOS / Linux (десктоп)',
                    'name'     => 'OlcBox — рекомендуется для ПК и ноутбуков',
                    'url'      => 'https://github.com/alananisimov/olcbox/releases',
                    'install_hint' => 'Скачайте .msi (Windows) или .dmg (macOS) → установите → ➕ → Paste from Clipboard → Connect',
                ],
                [
                    'platform' => 'Android (телефоны/планшеты)',
                    'name'     => 'owenclave — рекомендуется (быстрее всех обновляется + обход DPI)',
                    'url'      => 'https://github.com/owenewans/owenclave/releases',
                    'install_hint' => 'Скачайте app-*-release.apk → включите Неизвестные источники → установите → ➕ → Import from clipboard → Play',
                ],
            ];

            if ($uri === null || $uri === '') {
                $uriHint  = "🔑 Ключ готовится в течение 2–3 минут после покупки / регистрации. Обновите страницу ЛК через 1 минуту, или нажмите «🔄 Пересоздать инстанс» ниже.";
                $uriHint2 = "Если через 5 минут ключа всё ещё нет — напишите в поддержку (раздел «Контакты» в шапке сайта).";
            } else {
                $uriHint  = "✅ КЛЮЧ ВЫДАН! СКОПИРУЙТЕ эту строку выше (кнопка 📋 Копировать) и вставьте в клиент OlcBox или owenclave (раздел ➕ / Import URI). Потом нажмите Подключить.";
                $uriHint2 = "Формат ключа: olcrtc://jitsi?datachannel@https://meet... — у КАЖДОГО пользователя он СВОЙ УНИКАЛЬНЫЙ, не делитесь им с друзьями.";
            }

            return $this->success([
                'user_id'      => $user->id,
                'instance'     => $data['instance'] ?? null,
                'uri'          => $uri,
                'uri_copy_hint'=> $uriHint,
                'uri_warning'  => $uriHint2,
                'yaml'         => $yaml,
                'subscribe_url'=> $subUrl,
                'yaml_url'     => $yamlUrl,
                'client_downloads' => $clients,
                'knowledge_base_url' => url('/#/knowledge'),
                'expired_at'   => $user->expired_at,
                'plan_id'      => $user->plan_id,
                'banned'       => (bool) $user->banned,
                'is_active'    => $user->isActive(),
                'has_trial'    => ($user->expired_at && strtotime($user->expired_at) > time()),
                'panel_docs_hint' => 'Подробные инструкции: вкладка «📚 База знаний» в ЛК.',
            ]);
        } catch (\Throwable $e) {
            return $this->fail([500, $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/user/olcrtc/sub — plain text подписка в формате olcrtc sub.md
     * Доступен по user->token в query (без Bearer) — для того чтобы вставить URL в клиент owenclave/olcbox/Veil.
     */
    public function subscribe(Request $request)
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            $u = $request->user();
            if (!$u) {
                abort(401, 'missing token');
            }
            $user = User::find($u->id);
        } else {
            $user = User::where('token', $token)->first();
        }
        if (!$user) {
            abort(404, 'user not found');
        }
        if ($user->banned || !$user->isActive()) {
            return response("# subscription disabled\n# banned or expired\n", 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        try {
            $txt = $this->client()->getUserSub($user->id);
            if ($txt === null || $txt === '') {
                $txt = "# no active olcrtc instance\n";
            }
            return response($txt, 200)->header('Content-Type', 'text/plain; charset=utf-8');
        } catch (\Throwable $e) {
            return response("# olcrtc manager error: " . $e->getMessage() . "\n", 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * GET /api/v1/user/olcrtc/yaml — скачать client.yaml
     */
    public function yaml(Request $request)
    {
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([401, 'Unauthorized']);
        }
        try {
            $yaml = $this->client()->getUserYaml($user->id);
            if ($yaml === null) {
                return $this->fail([404, 'No olcrtc instance']);
            }
            return response($yaml, 200, [
                'Content-Type'        => 'application/yaml; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="olcrtc-client.yaml"',
            ]);
        } catch (\Throwable $e) {
            return $this->fail([500, $e->getMessage()]);
        }
    }

    private function client(): OlcRTCManagerClient
    {
        $url = rtrim((string) $this->getConfig('manager_url', 'http://127.0.0.1:8080'), '/');
        $key = (string) $this->getConfig('manager_api_key', '');
        $provider = (string) $this->getConfig('default_provider', 'jitsi');
        $transport = (string) $this->getConfig('default_transport', 'datachannel');
        $dns = (string) $this->getConfig('default_dns', '8.8.8.8:53');
        $token = (string) $this->getConfig('auth_token', '');
        return new OlcRTCManagerClient($url, $key, $provider, $transport, $dns, $token);
    }
}
