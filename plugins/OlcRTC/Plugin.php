<?php

namespace Plugin\OlcRTC;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;
use App\Models\User;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\OlcRTC\Payments\YooKassaPayment;
use Plugin\OlcRTC\Services\OlcRTCManagerClient;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private ?YooKassaPayment $yooKassaCached = null;

    public function boot(): void
    {
        // Регистрируем платёжную систему ЮKassa (YooKassa) — ПСБ, ЮMoney, карты, СБП, Tinkoff
        $this->filter('available_payment_methods', function (array $methods) {
            $methods['yookassa'] = [
                'name'        => 'ЮKassa (ЮMoney / Сбербанк / СБП / карты)',
                'plugin_code' => $this->getPluginCode(),
                'show_name'   => 'Банковская карта / Сбербанк / ЮMoney / СБП',
                'icon'        => '💳',
            ];
            return $methods;
        });

        // Хук: после регистрации пользователя - выдать триал
        $this->listen('user.register.after', function (User $user) {
            if (!$this->getConfig('trial_enabled', true)) {
                return;
            }
            try {
                $trialHours = (int) $this->getConfig('trial_hours', 6);
                $expiresAt = time() + max(1, $trialHours) * 3600;
                $this->client()->createOrUpdateInstance($user->id, $expiresAt, "trial {$trialHours}h");
                Log::info('[OlcRTC] trial created for user ' . $user->id);
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] trial create failed: ' . $e->getMessage());
            }
        });

        // Хук: после успешной оплаты и открытия заказа - продлить/создать инстанс
        $this->listen('order.open.after', function (Order $order) {
            try {
                $user = User::find($order->user_id);
                if (!$user) {
                    return;
                }
                $expiresAt = $user->expired_at ?? (time() + 30 * 86400);
                $comment = $this->getConfig('default_comment', 'olcrtc subscription');
                $this->client()->createOrUpdateInstance($user->id, (int) $expiresAt, $comment);
                Log::info('[OlcRTC] instance provisioned after order for user ' . $user->id);
            } catch (\Throwable $e) {
                Log::error('[OlcRTC] order.open.after failed: ' . $e->getMessage());
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
    // PaymentInterface — ЮKassa
    // =========================
    public function form(): array
    {
        return $this->yooKassa()->form();
    }

    public function pay($order): array
    {
        return $this->yooKassa()->pay($order);
    }

    public function notify($params)
    {
        return $this->yooKassa()->notify($params);
    }

    private function yooKassa(): YooKassaPayment
    {
        if ($this->yooKassaCached === null) {
            $this->yooKassaCached = new YooKassaPayment($this->config);
        }
        return $this->yooKassaCached;
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
