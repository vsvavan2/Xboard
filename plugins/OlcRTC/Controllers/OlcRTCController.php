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
            $data = $this->client()->getUserInstance($user->id);
            $uri  = $this->client()->getUserUri($user->id);
            $yaml = $this->client()->getUserYaml($user->id);

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

            $uriHint = "СКОПИРУЙТЕ эту строку выше (кнопка 📋 Копировать) и вставьте в клиент OlcBox или owenclave (раздел ➕ / Import URI). Потом нажмите Подключить.";

            return $this->success([
                'user_id'      => $user->id,
                'instance'     => $data['instance'] ?? null,
                'uri'          => $uri,
                'uri_copy_hint'=> $uriHint,
                'yaml'         => $yaml,
                'subscribe_url'=> $subUrl,
                'yaml_url'     => $yamlUrl,
                'client_downloads' => $clients,
                'knowledge_base_url' => url('/#/knowledge'),
                'expired_at'   => $user->expired_at,
                'plan_id'      => $user->plan_id,
                'banned'       => (bool) $user->banned,
                'is_active'    => $user->isActive(),
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
