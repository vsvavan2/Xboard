<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;

class PaymentService
{
    public $method;
    protected $config;
    protected $payment;
    protected $pluginManager;
    protected $class;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->pluginManager = app(PluginManager::class);

        if ($method === 'temp') {
            return;
        }

        if ($id) {
            $paymentModel = Payment::find($id);
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
        }
        if ($uuid) {
            $paymentModel = Payment::where('uuid', $uuid)->first();
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
        }

        $this->config = [];
        if (isset($payment)) {
            $this->config = is_string($payment['config']) ? json_decode($payment['config'], true) : $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'] ?? '';
        }

        // Имя метода оплаты (v2_payment.payment) — плагин по нему выбирает свой шлюз
        // (один плагин может обслуживать несколько методов, напр. yookassa и yoomoney).
        $this->config['_method'] = $this->method;

        $paymentMethods = $this->getAvailablePaymentMethods();
        if (isset($paymentMethods[$this->method])) {
            $pluginCode = $paymentMethods[$this->method]['plugin_code'];
            $paymentPlugins = $this->pluginManager->getEnabledPaymentPlugins();
            foreach ($paymentPlugins as $plugin) {
                if ($plugin->getPluginCode() === $pluginCode) {
                    $plugin->setConfig($this->config);
                    $this->payment = $plugin;
                    return;
                }
            }
        }

        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable'])
            throw new ApiException('gate is not enable');
        return $this->payment->notify($params);
    }

    /**
     * Собрать URL уведомлений платёжки.
     *
     * Поле `notify_domain` в админке — это ТОЛЬКО домен (например
     * `https://vless.24x7.hk`). Если туда случайно вставить полный URL вида
     * `https://vless.24x7.hk/api/v1/guest/payment/notify/yoomoney/XXXXXXXX`,
     * то при склейке `notify_domain + path` путь дублировался, и уведомления
     * от платёжной системы уходили в 404. Поэтому из значения берём только
     * схему, хост и порт, а путь всегда формируем сами.
     */
    public static function buildNotifyUrl(string $method, string $uuid, ?string $notifyDomain = null): string
    {
        $notifyUrl = url("/api/v1/guest/payment/notify/{$method}/{$uuid}");
        $domain = trim((string) $notifyDomain);
        if ($domain === '') {
            return $notifyUrl;
        }
        $path = parse_url($notifyUrl, PHP_URL_PATH) ?: '';
        $parts = parse_url($domain);
        if (!empty($parts['host'])) {
            $base = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $base .= ':' . $parts['port'];
            }
            return $base . $path;
        }
        // Домен без схемы (например "vless.24x7.hk")
        return 'https://' . trim($domain, '/') . $path;
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = self::buildNotifyUrl(
            (string) $this->method,
            (string) ($this->config['uuid'] ?? ''),
            $this->config['notify_domain'] ?? null
        );

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => source_base_url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $result = [];
        foreach ($form as $key => $field) {
            // Radix (админка) не умеет показывать select, если сохранённое значение
            // пустое, а в списке опций нет пустого варианта — тогда показываем
            // значение по умолчанию из описания поля.
            $value = $this->config[$key] ?? null;
            if ($value === null || $value === '') {
                $value = $field['default'] ?? '';
            }
            $result[$key] = [
                'type' => $field['type'],
                'label' => $field['label'] ?? '',
                'placeholder' => $field['placeholder'] ?? '',
                'description' => $field['description'] ?? '',
                'value' => $value,
                'options' => self::normalizeOptions($field['select_options'] ?? $field['options'] ?? []),
                'required' => (bool) ($field['required'] ?? false),
            ];
        }
        return $result;
    }

    /**
     * The admin (React) renders a `select` field from `options` as an array of
     * { value, label } objects. Plugins may pass either that array, or a simple
     * map ["AC" => "Банковская карта"] — normalize both into the array form.
     */
    protected static function normalizeOptions($options): array
    {
        if (!is_array($options) || $options === []) {
            return [];
        }

        // Already a list of { value, label } — pass through.
        if (array_is_list($options)) {
            $out = [];
            foreach ($options as $item) {
                if (is_array($item) && (isset($item['value']) || isset($item['label']))) {
                    $out[] = [
                        'value' => (string) ($item['value'] ?? ''),
                        'label' => (string) ($item['label'] ?? ($item['value'] ?? '')),
                    ];
                } elseif (is_scalar($item)) {
                    $out[] = ['value' => (string) $item, 'label' => (string) $item];
                }
            }
            return $out;
        }

        // Map form: value => label
        $out = [];
        foreach ($options as $value => $label) {
            if (is_array($label)) {
                $label = $label['label'] ?? ($label['name'] ?? $value);
            }
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        return $out;
    }

    /**
     * 获取所有可用的支付方式
     */
    public function getAvailablePaymentMethods(): array
    {
        $methods = [];

        $methods = HookManager::filter('available_payment_methods', $methods);

        return $methods;
    }

    /**
     * 获取所有支付方式名称列表（用于管理后台）
     */
    public static function getAllPaymentMethodNames(): array
    {
        $pluginManager = app(PluginManager::class);
        $pluginManager->initializeEnabledPlugins();

        $instance = new self('temp');
        $methods = $instance->getAvailablePaymentMethods();

        return array_keys($methods);
    }
}
