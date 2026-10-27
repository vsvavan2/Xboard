<?php

namespace Plugin\OlcRTC;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;
use App\Models\User;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\OlcRTC\Payments\YooKassaPayment;
use Plugin\OlcRTC\Payments\YooMoneyPayment;
use Plugin\OlcRTC\Services\OlcRTCManagerClient;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private ?YooKassaPayment $yooKassaCached = null;
    private ?YooMoneyPayment $yooMoneyCached = null;

    public function boot(): void
    {
        // Регистрируем платёжные системы плагина
        $this->filter('available_payment_methods', function (array $methods) {
            // ЮKassa (YooKassa) — карты/СБП/Сбербанк/ЮMoney через эквайринг ЮKassa
            $methods['yookassa'] = [
                'name'        => 'ЮKassa (ЮMoney / Сбербанк / СБП / карты)',
                'plugin_code' => $this->getPluginCode(),
                'show_name'   => 'Банковская карта / Сбербанк / ЮMoney / СБП',
                'icon'        => '💳',
            ];
            // ЮMoney напрямую на кошелёк (Quickpay) — БЕЗ ИП/организации, просто личный кошелёк
            $methods['yoomoney'] = [
                'name'        => 'ЮMoney (оплата на кошелёк, без ИП)',
                'plugin_code' => $this->getPluginCode(),
                'show_name'   => 'ЮMoney (кошелёк ЮMoney)',
                'icon'        => '🟣',
            ];
            return $methods;
        });

        // Хук: после регистрации пользователя - выдать триал
        $this->listen('user.register.after', function (User $user) {
            if (!$this->getConfig('trial_enabled', true)) {
                return;
            }
            try {
                $trialHours = (int) $this->getConfig('trial_hours', 72);
                $expiresAt = time() + max(1, $trialHours) * 3600;
                // Три ключа сразу: основной хост + обход белых списков + резервный.
                $res = $this->client()->provisionAllSlots($user->id, $expiresAt);
                Log::info('[OlcRTC] trial created for user ' . $user->id . ' (slots ok: ' . $res['ok'] . ')');
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] trial create failed: ' . $e->getMessage());
            }
        });

        // Хук: после успешной оплаты и открытия заказа - продлить/создать инстанс
        $this->listen('order.open.after', function (Order $order) {
            try {
                $user = User::find($order->user_id);
                if (!$user) {
                    Log::warning('[OlcRTC] order.open.after: user not found for order ' . $order->id);
                    return;
                }
                // Если expired_at не установлен или истёк, используем дефолт 30 дней
                $expiresAt = $user->expired_at;
                if (!$expiresAt || $expiresAt <= time()) {
                    $expiresAt = time() + 30 * 86400;
                }
                $this->client()->provisionAllSlots($user->id, (int) $expiresAt);
                Log::info('[OlcRTC] instance provisioned after order for user ' . $user->id . ', expires_at: ' . date('Y-m-d H:i:s', $expiresAt));
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] order.open.after failed for order ' . $order->id . ': ' . $e->getMessage());
            }
        });

        // Добавляем в конфиг фронтенда флаг активности плагина
        $this->filter('guest_comm_config', function ($config) {
            $config['olcrtc_enable'] = true;
            return $config;
        });
        $this->filter('user_comm_config', function ($config) {
            $config['olcrtc_enable'] = true;
            return $config;
        });
    }

    // =========================
    // PaymentInterface — ЮKassa / ЮMoney
    // =========================
    public function form(): array
    {
        return $this->gateway()->form();
    }

    public function pay($order): array
    {
        return $this->gateway()->pay($order);
    }

    public function notify($params)
    {
        return $this->gateway()->notify($params);
    }

    /**
     * Выбор активного платёжного шлюза по имени метода из БД (`v2_payment.payment`).
     * PaymentService кладёт имя метода в конфиг как `_method`.
     */
    private function gateway(): PaymentInterface
    {
        $method = (string) ($this->config['_method'] ?? 'yookassa');
        if ($method === 'yoomoney') {
            return $this->yooMoney();
        }
        return $this->yooKassa();
    }

    private function yooKassa(): YooKassaPayment
    {
        if ($this->yooKassaCached === null) {
            $this->yooKassaCached = new YooKassaPayment($this->config);
        }
        return $this->yooKassaCached;
    }

    private function yooMoney(): YooMoneyPayment
    {
        if ($this->yooMoneyCached === null) {
            $this->yooMoneyCached = new YooMoneyPayment($this->config);
        }
        return $this->yooMoneyCached;
    }

    public function schedule(Schedule $schedule): void
    {
        // Каждые 15 минут синхронизируем истекшие подписки (остановка на случай, если manager пропустил)
        $schedule->call(function () {
            try {
                $this->client()->syncExpiredUsers();
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] syncExpiredUsers cron failed: ' . $e->getMessage());
            }
        })->everyFifteenMinutes();

        // Каждый час проверяем онлайн-инстансы и пролонгируем активным пользователям
        $schedule->call(function () {
            try {
                $this->client()->reconcileActiveUsers();
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] reconcileActiveUsers cron failed: ' . $e->getMessage());
            }
        })->hourly();
    }

    public function install(): void
    {
        // Плагин не создаёт таблиц (стейт хранится в olcrtc-manager SQLite)
    }

    public function cleanup(): void
    {
        // При желании можно посылать stopAll, но оставляем инстансы живыми
    }

    /**
     * Настройки подключения к olcrtc-manager с многоуровневым резолвингом.
     *
     * ВАЖНО: плагин — это singleton, и `PaymentService::notify()` перезаписывает
     * его конфиг «платёжным» (shop_id/wallet/uuid/…), в котором НЕТ ключей
     * manager_url. Из-за этого хук `order.open.after` (провижининг после оплаты)
     * брал дефолт `http://127.0.0.1:8080` и падал с cURL error 7 — ключ выдавался
     * только потому, что виджет потом досоздавал инстанс лениво. Поэтому здесь
     * тот же порядок резолвинга, что и у виджета: живой конфиг → строка плагина в
     * БД → настройки админки → ENV контейнера → дефолт docker-сервиса.
     */
    private function managerConfig(): array
    {
        $conf = [
            'manager_url'       => '',
            'manager_api_key'   => '',
            'default_provider'  => '',
            'default_transport' => '',
            'default_dns'       => '',
            'auth_token'        => '',
        ];
        $keys = array_keys($conf);

        // 1) Живой конфиг плагина (актуален, пока его не перезаписал платёжный слой)
        foreach ($keys as $k) {
            $v = (string) $this->getConfig($k, '');
            if ($v !== '') {
                $conf[$k] = $v;
            }
        }

        // 2) Строка плагина `olc_rtc` в БД — источник истины при перезаписи конфига
        try {
            $row = \App\Models\Plugin::where('code', 'olc_rtc')->first();
            if ($row && !empty($row->config)) {
                $cfg = is_array($row->config) ? $row->config : (json_decode((string) $row->config, true) ?: []);
                foreach ($keys as $k) {
                    if ($conf[$k] === '' && isset($cfg[$k]) && (string) $cfg[$k] !== '') {
                        $conf[$k] = (string) $cfg[$k];
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        // 3) Настройки админки (v2_setting.olcrtc_*)
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

        // 4) ENV контейнера
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

        // 5) Осмысленные дефолты для docker-сети (никакого 127.0.0.1)
        $conf['manager_url'] = OlcRTCManagerClient::resolveBaseUrl((string) $conf['manager_url']);
        if ($conf['default_provider'] === '')  $conf['default_provider'] = 'jitsi';
        if ($conf['default_transport'] === '') $conf['default_transport'] = 'datachannel';
        if ($conf['default_dns'] === '')       $conf['default_dns'] = '77.88.8.8:53';

        return $conf;
    }

    private function client(): OlcRTCManagerClient
    {
        $conf = $this->managerConfig();
        $url = rtrim((string) $conf['manager_url'], '/');
        $key = OlcRTCManagerClient::resolveApiKey($url, (string) $conf['manager_api_key']);
        return new OlcRTCManagerClient(
            $url,
            $key,
            (string) $conf['default_provider'],
            (string) $conf['default_transport'],
            (string) $conf['default_dns'],
            (string) $conf['auth_token'],
        );
    }
}
