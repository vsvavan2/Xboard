<?php

namespace Plugin\OlcRTC\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * ЮMoney (YooMoney) — приём оплаты на кошелёк через Quickpay-форму.
 *
 * ⚠️ НЕ требует ИП или организации: достаточно обычного личного кошелька ЮMoney.
 *    Никаких договоров, касс и API-ключей — только номер кошелька и
 *    «секрет для уведомлений» из настроек кошелька.
 *
 * Как это работает:
 *   1. Пользователь нажимает «Оплатить» → мы редиректим его на
 *      https://yoomoney.ru/quickpay/confirm.xml с параметрами
 *      receiver (кошелёк), sum (сумма), label (номер заказа), successURL.
 *   2. После оплаты ЮMoney присылает HTTP-уведомление (POST) на Notification URI
 *      с параметрами и подписью sha1_hash.
 *   3. Мы проверяем sha1_hash с помощью «секрета для уведомлений» и подтверждаем заказ.
 *
 * Настройка в ЛК ЮMoney: https://yoomoney.ru/notifications
 *   - Там же указать Notification URI:
 *     https://<ваш-домен>/api/v1/guest/payment/notify/yoomoney/<UUID из админки>
 */
class YooMoneyPayment implements PaymentInterface
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
            'wallet' => [
                'type'        => 'string',
                'label'       => 'Номер кошелька ЮMoney',
                'placeholder' => '4100112345678901',
                'description' => 'Только цифры. Номер вашего кошелька ЮMoney (он же «receiver» в форме оплаты). Достаточно ЛИЧНОГО кошелька — ИП/организация НЕ нужны.',
                'default'     => '',
            ],
            'secret' => [
                'type'        => 'string',
                'label'       => 'Секрет для уведомлений (notification_secret)',
                'placeholder' => 'скопируйте на yoomoney.ru/notifications',
                'description' => 'Возьмите в ЛК ЮMoney → Настройки → Уведомления (https://yoomoney.ru/notifications). По нему проверяется подлинность каждого платежа (sha1_hash). Без него оплата не будет зачисляться автоматически.',
                'default'     => '',
            ],
            'payment_type' => [
                'type'        => 'string',
                'label'       => 'Способ оплаты по умолчанию (опционально)',
                'placeholder' => 'AC или PC (можно оставить пустым)',
                'description' => 'AC — банковская карта, PC — кошелёк ЮMoney. Оставьте ПУСТЫМ — ЮMoney сам предложит выбор способа оплаты пользователю.',
                'default'     => '',
            ],
            'success_url' => [
                'type'        => 'string',
                'label'       => 'URL возврата после оплаты (опционально)',
                'placeholder' => 'оставьте пустым — вернём на страницу заказа',
                'description' => 'Куда вернуть пользователя после успешной оплаты. Оставьте ПУСТЫМ — вернём на страницу заказа в личном кабинете.',
                'default'     => '',
            ],
        ];
    }

    /**
     * Создать ссылку оплаты и вернуть её для редиректа в браузере.
     */
    public function pay($order): array
    {
        $wallet = preg_replace('/\D+/', '', (string) ($this->config['wallet'] ?? '')) ?? '';
        if ($wallet === '') {
            throw new ApiException('ЮMoney не настроена: в админке не указан «Номер кошелька ЮMoney»');
        }

        $amountKop = (int) ($order['total_amount'] ?? 0);
        if ($amountKop <= 0) {
            // Нольрублёвый заказ — сразу считаем оплаченным
            return [
                'type' => 1,
                'data' => $order['return_url'],
            ];
        }
        $amountRub = number_format($amountKop / 100, 2, '.', '');

        $successUrl = trim((string) ($this->config['success_url'] ?? ''));
        if ($successUrl === '') {
            $successUrl = (string) $order['return_url'];
        }

        $params = [
            'receiver'      => $wallet,
            'quickpay-form' => 'shop',
            'targets'       => 'Оплата подписки #' . $order['trade_no'],
            'sum'           => $amountRub,
            'label'         => (string) $order['trade_no'],
            'successURL'    => $successUrl,
        ];

        $paymentType = strtoupper(trim((string) ($this->config['payment_type'] ?? '')));
        if (in_array($paymentType, ['AC', 'PC'], true)) {
            $params['paymentType'] = $paymentType;
        }

        $url = 'https://yoomoney.ru/quickpay/confirm.xml?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        Log::info('[OlcRTC-YooMoney] quickpay link created: trade_no=' . $order['trade_no'] . ' sum=' . $amountRub);

        // type=1 → фронтенд делает window.location.href = data (редирект на ЮMoney)
        return [
            'type' => 1,
            'data' => $url,
        ];
    }

    /**
     * Обработка HTTP-уведомления от ЮMoney.
     */
    public function notify($params)
    {
        $data = is_array($params) ? $params : [];
        if ($data === []) {
            $data = array_merge($_POST ?? [], $_GET ?? []);
        }

        Log::info('[OlcRTC-YooMoney] notify: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        $label       = (string) ($data['label'] ?? '');
        $operationId = (string) ($data['operation_id'] ?? '');
        $sha1        = (string) ($data['sha1_hash'] ?? ($data['sha1'] ?? ''));

        if ($label === '' || !$this->verifySignature($data)) {
            Log::warning('[OlcRTC-YooMoney] notify verify FAILED for label=' . $label);
            return false;
        }

        $codepro = $data['codepro'] ?? '';
        $codepro = is_bool($codepro) ? ($codepro ? 'true' : 'false') : (string) $codepro;
        Log::info('[OlcRTC-YooMoney] notify VERIFIED label=' . $label
            . ' operation_id=' . $operationId
            . ' amount=' . ($data['amount'] ?? '')
            . ' codepro=' . $codepro);

        // Заказ считается оплаченным. trade_no == label, callback_no == operation_id.
        return [
            'trade_no'    => $label,
            'callback_no' => $operationId !== '' ? $operationId : $label,
        ];
    }

    /**
     * Проверка подписи sha1_hash уведомления ЮMoney.
     *
     * Базовая строка: notification_type&operation_id&amount&currency&datetime&sender&codepro&secret&label
     * (существуют варианты нормализации, поэтому перебираем несколько комбинаций — как в рабочих
     *  интеграциях, чтобы не терять платежи из-за разного форматирования суммы/валюты).
     */
    private function verifySignature(array $data): bool
    {
        $secret = trim((string) ($this->config['secret'] ?? ''));
        $sha1   = (string) ($data['sha1_hash'] ?? ($data['sha1'] ?? ''));
        if ($secret === '' || $sha1 === '') {
            return false;
        }

        $amountStr    = isset($data['amount']) ? (string) $data['amount'] : '0.00';
        $withdrawStr  = isset($data['withdraw_amount']) ? (string) $data['withdraw_amount'] : '';
        $currency     = (string) ($data['currency'] ?? '643');
        $operationId  = (string) ($data['operation_id'] ?? '');
        $opLabel      = (string) ($data['operation_label'] ?? '');
        $notification = (string) ($data['notification_type'] ?? '');
        $datetime     = (string) ($data['datetime'] ?? '');
        $sender       = (string) ($data['sender'] ?? '');
        $label        = (string) ($data['label'] ?? '');

        $cpRaw  = $data['codepro'] ?? '';
        $codepro = is_bool($cpRaw)
            ? ($cpRaw ? 'true' : 'false')
            : (in_array(strtolower((string) $cpRaw), ['true', '1', 'yes'], true) ? 'true' : 'false');

        // Варианты валюты
        $curVariants = [$currency];
        if (strcasecmp($currency, 'RUB') === 0) {
            $curVariants[] = '643';
        } elseif ($currency === '643') {
            $curVariants[] = 'RUB';
        }

        // Варианты label
        $labelVariants = [$label];
        if ($opLabel !== '' && $opLabel !== $label) {
            $labelVariants[] = $opLabel;
        }

        // Варианты notification_type (в старых интеграциях мог не участвовать)
        $notifVariants = [$notification];
        if ($notification !== '') {
            $notifVariants[] = '';
        }

        // Варианты codepro
        $cpVariants = array_values(array_unique([$codepro, 'false', '0', '']));

        // Варианты суммы
        $amountVariants = [$amountStr];
        $amountDot = number_format((float) str_replace(',', '.', $amountStr), 2, '.', '');
        foreach ([$amountDot, str_replace('.', ',', $amountDot), str_replace(',', '.', $amountStr)] as $v) {
            if (!in_array($v, $amountVariants, true)) {
                $amountVariants[] = $v;
            }
        }
        if ($withdrawStr !== '') {
            $wDot = number_format((float) str_replace(',', '.', $withdrawStr), 2, '.', '');
            foreach ([$withdrawStr, $wDot, str_replace('.', ',', $wDot)] as $v) {
                if (!in_array($v, $amountVariants, true)) {
                    $amountVariants[] = (string) $v;
                }
            }
        }

        foreach ($curVariants as $cur) {
            foreach ($amountVariants as $amt) {
                foreach ($labelVariants as $lbl) {
                    foreach ($notifVariants as $nt) {
                        foreach ($cpVariants as $cp) {
                            $bases = [
                                // Основной порядок: secret перед label
                                $nt . '&' . $operationId . '&' . $amt . '&' . $cur . '&' . $datetime . '&' . $sender . '&' . $cp . '&' . $secret . '&' . $lbl,
                                // Легаси-порядок: label перед secret
                                $nt . '&' . $operationId . '&' . $amt . '&' . $cur . '&' . $datetime . '&' . $sender . '&' . $cp . '&' . $lbl . '&' . $secret,
                            ];
                            foreach ($bases as $base) {
                                if (hash_equals($sha1, sha1($base))) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }
}
