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
 * Настройка в ЛК ЮMoney (HTTP-уведомления):
 *   https://yoomoney.ru/transfer/myservices/http-notification
 *   (раздел «Настройки» → «HTTP-уведомления»). Там же указать Notification URI:
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
     *
     * Показываются в админке в разделе «Платёжные системы» → при выборе
     * метода «ЮMoney (оплата на кошелёк, без ИП)». Обязательных полей всего два:
     * номер кошелька и секрет для уведомлений — этого достаточно для приёма оплат.
     */
    public function form(): array
    {
        return [
            'wallet' => [
                'type'        => 'string',
                'label'       => '1) Номер кошелька ЮMoney (receiver)',
                'placeholder' => '4100112345678901',
                'description' => 'Только цифры — номер ВАШЕГО кошелька ЮMoney (начинается на 41001…). Где взять: войдите на https://yoomoney.ru — номер кошелька показан в правом верхнем углу рядом с вашим именем. ИП и организация НЕ нужны: подойдёт обычный личный кошелёк.',
                'required'    => true,
                'default'     => '',
            ],
            'secret' => [
                'type'        => 'string',
                'label'       => '2) Секрет для уведомлений (notification_secret)',
                'placeholder' => 'вставьте секрет из ЛК ЮMoney (Настройки → HTTP-уведомления)',
                'description' => 'Откройте https://yoomoney.ru/transfer/myservices/http-notification (ЛК ЮMoney → «Настройки» → «HTTP-уведомления») → включите галочку «Отправлять HTTP-уведомления» → нажмите «Показать секрет» и вставьте его сюда. Туда же в поле «Куда отправлять (URL сайта)» вставьте ссылку из колонки «Webhook URL» в списке платёжных систем (формат: https://ваш-домен/api/v1/guest/payment/notify/yoomoney/XXXXXXXX). ВАЖНО: не путайте этот секрет с «Секретом приложения» из поля 6 — это разные значения. Без верного секрета платежи не зачисляются автоматически.',
                'required'    => true,
                'default'     => '',
            ],
            'payment_type' => [
                'type'        => 'select',
                'label'       => '3) Способ оплаты на странице ЮMoney',
                'placeholder' => 'Авто — пусть плательщик выберет сам',
                'description' => 'Что предложить плательщику. Рекомендуется «Авто» — ЮMoney сам покажет оба способа. «Карта» (AC) — сразу форма карты, «Кошелёк» (PC) — сразу вход в кошелёк ЮMoney.',
                'default'     => 'auto',
                'options'     => [
                    'auto' => 'Авто — пусть плательщик выберет сам (рекомендуется)',
                    'AC'   => 'Банковская карта (AC)',
                    'PC'   => 'Кошелёк ЮMoney (PC)',
                ],
            ],
            'success_url' => [
                'type'        => 'string',
                'label'       => '4) Куда вернуть пользователя после оплаты (необязательно)',
                'placeholder' => 'оставьте пустым — вернём в личный кабинет на страницу заказа',
                'description' => 'Оставьте поле ПУСТЫМ — после оплаты пользователь вернётся в личный кабинет на страницу своего заказа. Свой адрес указывайте только если нужен отдельный «спасибо»-лендинг (начинается с http:// или https://).',
                'default'     => '',
            ],
            // --- Приложение ЮMoney (OAuth) — НЕОБЯЗАТЕЛЬНО ---
            // Поля ниже нужны только если вы зарегистрировали приложение на
            // https://yoomoney.ru/settings/oauth-services (кнопка «Доступ к кошельку»).
            // Приём оплаты на кошелёк (Quickpay, поля 1-2) работает и БЕЗ них.
            'client_id' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => '5) Идентификатор приложения ЮMoney (client_id) — необязательно',
                'placeholder' => 'например B863EC152B872B13E02CEB55152E01C9840E26720888A94BAB47BF2523720B6C',
                'description' => 'Если вы создали приложение в ЮMoney (https://yoomoney.ru/settings/oauth-services → «Доступ к кошельку»), вставьте сюда «Идентификатор приложения (client_id)». Нужен только для OAuth-сценария: чтобы «подключить» кошелёк к сайту, откройте в браузере https://<ваш-домен>/yoomoney/connect (после заполнения полей 5-6). На приём оплаты на кошелёк через Quickpay не влияет.',
                'default'     => '',
            ],
            'client_secret' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => '6) Секрет приложения ЮMoney (OAuth2 client_secret) — необязательно',
                'placeholder' => 'вставьте OAuth2 client_secret из настроек приложения',
                'description' => 'Секрет приложения из того же окна «Данные приложения». Нужен вместе с client_id для OAuth. Не путайте с «секретом для уведомлений» из поля 2 — это разные значения.',
                'default'     => '',
            ],
            'redirect_uri' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => '7) Redirect URI приложения (необязательно)',
                'placeholder' => 'оставьте пустым — будет https://<ваш-домен>/yoomoney/callback',
                'description' => 'Адрес возврата приложения ЮMoney. Должен совпадать с полем «Redirect URI» в настройках приложения (например, https://vless.24x7.hk/yoomoney/callback). Оставьте пустым — подставим автоматически.',
                'default'     => '',
            ],
            'notification_uri' => [
                'type'        => 'string',
                'required'    => false,
                'label'       => '8) Notification URI приложения (необязательно)',
                'placeholder' => 'оставьте пустым — будет https://<ваш-домен>/yoomoney-webhook',
                'description' => 'Адрес, куда ЮMoney присылает уведомления об операциях. Должен совпадать с полем «Notification URI» в настройках приложения (например, https://vless.24x7.hk/yoomoney-webhook). Оставьте пустым — подставим автоматически.',
                'default'     => '',
            ],
        ];
    }

    /**
     * Адрес возврата (Redirect URI) приложения ЮMoney.
     */
    public function redirectUri(): string
    {
        $uri = trim((string) ($this->config['redirect_uri'] ?? ''));
        return $uri !== '' ? $uri : url('/yoomoney/callback');
    }

    /**
     * Адрес уведомлений (Notification URI) приложения ЮMoney.
     */
    public function notificationUri(): string
    {
        $uri = trim((string) ($this->config['notification_uri'] ?? ''));
        return $uri !== '' ? $uri : url('/yoomoney-webhook');
    }

    /**
     * Ссылка авторизации приложения ЮMoney (первый шаг OAuth).
     *
     * Владелец кошелька открывает её один раз → подтверждает доступ приложению →
     * ЮMoney возвращает его на Redirect URI (/yoomoney/callback) с `code`,
     * который затем меняется на access_token в exchangeCode().
     * Без этого шага сохранённые client_id / client_secret никуда не ведут.
     *
     * @param string $state Непрозрачный идентификатор платёжки (v2_payment.uuid).
     */
    public function authorizeUrl(string $state = ''): string
    {
        $clientId = trim((string) ($this->config['client_id'] ?? ''));
        if ($clientId === '') {
            throw new ApiException('ЮMoney приложение не настроено: заполните «Идентификатор приложения (client_id)» в админке.');
        }
        if (trim($state) === '') {
            $state = bin2hex(random_bytes(4));
        }

        return 'https://yoomoney.ru/oauth/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => 'account-info operation-history',
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Обмен OAuth-кода на токен доступа (шаг после Redirect URI).
     * Используется, когда приложение ЮMoney авторизовано владельцем кошелька.
     */
    public function exchangeCode(string $code): array
    {
        $clientId     = trim((string) ($this->config['client_id'] ?? ''));
        $clientSecret = trim((string) ($this->config['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || trim($code) === '') {
            throw new ApiException('ЮMoney приложение не настроено: заполните client_id и client_secret в админке.');
        }

        $resp = \Illuminate\Support\Facades\Http::asForm()->timeout(20)->post('https://yoomoney.ru/oauth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => trim($code),
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $this->redirectUri(),
        ]);

        $json = $resp->json();
        if (!is_array($json) || empty($json['access_token'])) {
            throw new ApiException('ЮMoney OAuth: не удалось получить токен (HTTP ' . $resp->status() . ')');
        }
        return $json;
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
