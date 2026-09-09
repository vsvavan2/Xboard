<?php

namespace Plugin\OlcRTC\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ЮKassa (YooKassa) платёжный шлюз.
 * Поддерживает: банковские карты МИР/Visa/MC, Сбербанк Онлайн, ЮMoney (Яндекс.Деньги), СБП, Tinkoff.
 *
 * Документация: https://yookassa.ru/developers/api
 */
class YooKassaPayment implements PaymentInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Поля настроек платёжки в админке Xboard.
     */
    public function form(): array
    {
        return [
            'shop_id' => [
                'type'        => 'string',
                'label'       => 'Shop ID ЮKassa',
                'placeholder' => 'Например, 548791',
                'description' => 'Возьмите в личном кабинете ЮKassa → Настройки → Магазин',
            ],
            'secret_key' => [
                'type'        => 'string',
                'label'       => 'Секретный ключ (Bearer)',
                'placeholder' => 'live_... или test_...',
                'description' => 'Ключ выданный ЮKassa для интеграции',
            ],
            'payment_methods' => [
                'type'        => 'string',
                'label'       => 'Методы оплаты (через запятую)',
                'default'     => 'bank_card,sberbank,yoomoney,sbp,tinkoff_bank',
                'description' => 'Доступные: bank_card, sberbank, yoomoney, sbp, tinkoff_bank, qiwi, alfabank',
            ],
            'locale' => [
                'type'        => 'string',
                'label'       => 'Язык формы',
                'default'     => 'ru-RU',
                'description' => 'ru-RU или en-US',
            ],
            'capture' => [
                'type'        => 'boolean',
                'label'       => 'Автоматический capture',
                'default'     => true,
                'description' => 'Сразу списывать деньги (рекомендуется true)',
            ],
            'send_receipt' => [
                'type'        => 'boolean',
                'label'       => 'Отправлять чек (54-ФЗ)',
                'default'     => false,
                'description' => 'Включите, если у вас подключена онлайн-касса через ЮKassa',
            ],
            'tax_system_code' => [
                'type'        => 'number',
                'label'       => 'Код СНО',
                'default'     => 1,
                'description' => '1 - ОСН, 2 - УСН доход, 3 - УСН доход-расход, 4 - ЕНВД, 5 - ЕСХН, 6 - ПСН',
            ],
            'vat_code' => [
                'type'        => 'number',
                'label'       => 'Ставка НДС',
                'default'     => 1,
                'description' => '1 - без НДС, 2 - 0%, 3 - 10%, 4 - 20%, 5 - расчет 10/110, 6 - расчет 20/120',
            ],
        ];
    }

    /**
     * Создать платёж и вернуть URL редиректа.
     */
    public function pay($order): array
    {
        $shopId   = (string) ($this->config['shop_id'] ?? '');
        $secret   = (string) ($this->config['secret_key'] ?? '');
        if ($shopId === '' || $secret === '') {
            throw new ApiException('YooKassa не настроена (shop_id/secret_key)');
        }

        $amountKop = (int) ($order['total_amount'] ?? 0);
        if ($amountKop <= 0) {
            // Нольрублёвый заказ - сразу считаем оплаченным
            return [
                'type' => 1,
                'data' => $order['return_url'],
            ];
        }
        $amountRub = number_format($amountKop / 100, 2, '.', '');

        $methods = array_values(array_filter(array_map('trim', explode(',', (string) ($this->config['payment_methods'] ?? '')))));
        if ($methods === []) {
            $methods = ['bank_card', 'sberbank', 'yoomoney', 'sbp', 'tinkoff_bank'];
        }

        $idempotency = $order['trade_no'] . '-' . time();
        $body = [
            'amount' => [
                'value'    => $amountRub,
                'currency' => 'RUB',
            ],
            'capture'       => (bool) ($this->config['capture'] ?? true),
            'description'   => 'Оплата подписки #' . $order['trade_no'],
            'confirmation'  => [
                'type'      => 'redirect',
                'return_url' => (string) $order['return_url'],
                'locale'    => (string) ($this->config['locale'] ?? 'ru-RU'),
            ],
            'metadata' => [
                'order_trade_no' => (string) $order['trade_no'],
                'user_id'        => (string) $order['user_id'],
            ],
            'payment_method_data' => [
                'type' => count($methods) === 1 ? $methods[0] : '',
            ],
        ];
        if (count($methods) > 1) {
            // Если несколько методов - ЮKassa покажет селектор на платёжной странице
            unset($body['payment_method_data']);
        }
        if (!empty($this->config['send_receipt'])) {
            $body['receipt'] = [
                'customer' => [
                    'email' => 'user_' . $order['user_id'] . '@local',
                ],
                'tax_system_code' => (int) ($this->config['tax_system_code'] ?? 1),
                'items' => [
                    [
                        'description' => 'VPN-услуга (подписка OlcRTC)',
                        'quantity'    => '1.00',
                        'amount'      => [
                            'value'    => $amountRub,
                            'currency' => 'RUB',
                        ],
                        'vat_code'    => (int) ($this->config['vat_code'] ?? 1),
                        'payment_mode' => 'full_prepayment',
                        'payment_subject' => 'service',
                    ],
                ],
            ];
        }

        $resp = Http::withBasicAuth($shopId, $secret)
            ->withHeader('Idempotence-Key', $idempotency)
            ->acceptJson()
            ->post('https://api.yookassa.ru/v3/payments', $body);

        Log::info('[OlcRTC-YooKassa] create payment trade_no=' . $order['trade_no'] . ' status=' . $resp->status() . ' body=' . $resp->body());

        if (!$resp->successful()) {
            Log::error('[OlcRTC-YooKassa] create failed: ' . $resp->body());
            throw new ApiException('Не удалось создать платёж в ЮKassa. Попробуйте позже.');
        }

        $json = $resp->json();
        $confirmationUrl = $json['confirmation']['confirmation_url'] ?? '';
        if ($confirmationUrl === '') {
            throw new ApiException('ЮKassa не вернула ссылку на оплату');
        }

        // type=2 → redirect на URL (Xboard сам открывает)
        return [
            'type' => 2,
            'data' => $confirmationUrl,
        ];
    }

    /**
     * Обработка вебхука от ЮKassa.
     */
    public function notify($params)
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        Log::info('[OlcRTC-YooKassa] notify: ' . $raw);

        $event = $body['event'] ?? '';
        $pay   = $body['object'] ?? [];

        // Простая проверка auth: должен прийти Basic auth shopId:secret
        $auth = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
        $expectedShop = (string) ($this->config['shop_id'] ?? '');
        $expectedKey  = (string) ($this->config['secret_key'] ?? '');
        if (($auth !== '' && $pass !== '') && ($auth !== $expectedShop || $pass !== $expectedKey)) {
            Log::warning('[OlcRTC-YooKassa] bad basic auth in notify');
            throw new ApiException('bad auth');
        }

        if (!in_array($event, ['payment.succeeded', 'payment.waiting_for_capture'], true)) {
            // Не успешное событие - отвечаем 200, ничего не делаем
            return true;
        }

        $status = $pay['status'] ?? '';
        $paid   = (bool) ($pay['paid'] ?? false);
        $tradeNo = (string) ($pay['metadata']['order_trade_no'] ?? '');
        if ($tradeNo === '') {
            // Пробуем взять из order_trade_no который мы передали ранее
            $tradeNo = (string) ($pay['metadata']['order_trade_no'] ?? '');
        }
        if ($tradeNo === '') {
            Log::warning('[OlcRTC-YooKassa] empty order_trade_no in metadata');
            return true;
        }

        if ($event === 'payment.waiting_for_capture' && !empty($this->config['capture'])) {
            // Если auto capture включен, но ЮKassa выставила waiting_for_capture - подтвердим
            $paymentId = (string) ($pay['id'] ?? '');
            if ($paymentId !== '') {
                $captureResp = Http::withBasicAuth($expectedShop, $expectedKey)
                    ->withHeader('Idempotence-Key', 'cap-' . $paymentId)
                    ->acceptJson()
                    ->post("https://api.yookassa.ru/v3/payments/{$paymentId}/capture", [
                        'amount' => $pay['amount'] ?? [],
                    ]);
                Log::info('[OlcRTC-YooKassa] auto capture: ' . $captureResp->status() . ' ' . $captureResp->body());
            }
        }

        if ($paid && ($status === 'succeeded' || $status === 'waiting_for_capture')) {
            return [
                'trade_no' => $tradeNo,
                'callback_no' => (string) ($pay['id'] ?? $tradeNo),
            ];
        }

        return true;
    }
}
