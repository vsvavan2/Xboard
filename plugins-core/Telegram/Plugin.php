<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => 'Начать работу / приветствие', 'handler' => 'handleStartCommand'],
    '/bind' => ['description' => 'Привязать аккаунт XBoard', 'handler' => 'handleBindCommand'],
    '/traffic' => ['description' => 'Статистика расхода трафика', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl' => ['description' => 'Получить ссылку на подписку', 'handler' => 'handleGetLatestUrlCommand'],
    '/unbind' => ['description' => 'Отвязать Telegram от аккаунта', 'handler' => 'handleUnbindCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();

    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }

    $payment = $order->payment;
    if (!$payment) {
      Log::warning('Telegram: уведомление об оплате не отправлено — у заказа нет способа оплаты', ['order_id' => $order->id]);
      return;
    }

    $message = sprintf(
      "💰 Успешная оплата %s руб.\n" .
      "———————————————\n" .
      "Платёжная система: %s\n" .
      "Наименование шлюза: %s\n" .
      "Номер заказа (site): `%s`",
      $order->total_amount / 100,
      Helper::escapeMarkdown($payment->payment),
      Helper::escapeMarkdown($payment->name),
      $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, true);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }

    $message = $ticket->messages()->latest()->first();
    $user = User::find($ticket->user_id);
    if (!$user)
      return;
    $user->load('plan');
    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : 'Бессрочно';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $ip = request()?->ip() ?? '';
    $region = $ip ? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : 'NULL') : '';
    $TGmessage = "📮 *Напоминание о тикете* #{$ticket->id}\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📧 E-mail пользователя: `{$user->email}`\n";
    $TGmessage .= "📍 Геолокация IP: `{$region}`\n";

    if ($plan) {
      $TGmessage .= "📦 Тариф: `" . Helper::escapeMarkdown($plan->name) . "`\n";
      $TGmessage .= "📊 Трафик: `{$remaining_traffic}G / {$transfer_enable}G` (Остаток / Всего)\n";
      $TGmessage .= "⬆️⬇️  Израсходовано: `{$u}G / {$d}G`\n";
      $TGmessage .= "⏰ Дата окончания: `{$expired_at}`\n";
    } else {
      $TGmessage .= "📦 Тариф: `Активного тарифа нет`\n";
    }

    $TGmessage .= "💰 Баланс личного кабинета: `{$money} руб.`\n";
    $TGmessage .= "💸 Партнёрский остаток: `{$affmoney} руб.`\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📝 *Тема тикета*: `" . Helper::escapeMarkdown($ticket->subject) . "`\n";
    $TGmessage .= "💬 *Содержимое сообщения*: `" . Helper::escapeMarkdown($message->message) . "`";
    $this->telegramService->sendMessageWithAdmin($TGmessage, true);
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }

    $this->registerReplyHandler('/(📮.*?Напоминание о тикете.*?#?|ID тикета: ?)(\\d+)/u', [$this, 'handleTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  /**
   * Отправить текстовое сообщение пользователю Telegram
   */
  protected function sendMessage(object $msg, string $message): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown');
  }

  /**
   * Проверить, что команда вызвана в личном сообщении, а не в группе
   */
  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '⚠️  Эту команду можно использовать только в личных сообщениях с ботом.');
      return false;
    }
    return true;
  }

  /**
   * Получить модель пользователя, привязанного к текущему Telegram-аккаунту
   */
  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $this->sendMessage($msg, '🔐 К вашему Telegram ещё не привязан ни один аккаунт XBoard. Отправьте /bind [ссылка_на_подписку] чтобы привязать.');
      return null;
    }
    return $user;
  }

  public function handleStartCommand(object $msg): void
  {
    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 Добро пожаловать в Telegram-бот XBoard VPN!');
    $botDescription = $this->getConfig('start_bot_description', '🤖 Я ваш персональный помощник. Умею:\n• Привязывать ваш аккаунт XBoard\n• Показывать статистику расхода трафика\n• Выдавать актуальные ссылки на подписку\n• Управлять привязкой аккаунта');
    $footer = $this->getConfig('start_footer', '💡 Подсказка: все команды работают только в личных сообщениях с ботом');

    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      $welcomeText .= "✅ Аккаунт XBoard уже привязан: {$user->email}\n\n";
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 Доступные команды:\n/traffic — статистика расхода трафика\n/getlatesturl — получить ссылку на подписку\n/unbind — отвязать Telegram от аккаунта');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 Сначала привяжите ваш аккаунт XBoard:\n1. Войдите в личный кабинет на сайте\n2. Скопируйте ссылку на подписку (раздел «Моя подписка»)\n3. Отправьте команду /bind и вставьте скопированную ссылку') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 Доступные команды:\n/bind [ссылка_на_подписку] — привязать аккаунт');
    }

    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);

    $this->sendMessage($msg, $welcomeText);
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled)
      return $handled;

    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram: необработанная ошибка при обработке команды', [
        'command' => $msg->command ?? 'unknown',
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);

      if (isset($msg->chat_id)) {
        $this->telegramService->sendMessage($msg->chat_id, '🔧 Система временно перегружена, повторите попытку через 1-2 минуты.');
      }

      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) {
      return false;
    }

    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) {
      return false;
    }

    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }

    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message')
      return;

    $helpText = $this->getConfig('help_text', 'Используйте команды:\n/bind — привязать аккаунт\n/traffic — посмотреть трафик\n/getlatesturl — получить свежую ссылку');
    $helpText = str_replace('\\n', "\n", $helpText);
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram: ошибка обработки сообщения', [
      'chat_id' => $msg->chat_id ?? 'unknown',
      'command' => $msg->command ?? 'unknown',
      'message_type' => $msg->message_type ?? 'unknown',
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) {
      $this->sendMessage($msg, '❌  Нужно передать ссылку на подписку. Пример: /bind https://vpn.site/api/v1/client/subscribe?token=xxx');
      return;
    }

    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) {
      $this->sendMessage($msg, '❌  Ссылка на подписку некорректная. Скопируйте её из раздела «Моя подписка» в личном кабинете.');
      return;
    }

    $user = User::where('token', $token)->first();
    if (!$user) {
      $this->sendMessage($msg, '❌  Пользователь с таким токеном подписки не найден в базе.');
      return;
    }

    if ($user->telegram_id) {
      $this->sendMessage($msg, '⚠️  Этот аккаунт XBoard уже привязан к другому Telegram-пользователю.');
      return;
    }

    $user->telegram_id = $msg->chat_id;
    if (!$user->save()) {
      $this->sendMessage($msg, '❌  При сохранении привязки возникла ошибка на сервере, повторите попытку.');
      return;
    }

    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, "✅  Аккаунт XBoard успешно привязан к вашему Telegram!\nE-mail: `{$user->email}`");
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsedUrl = parse_url($url);

    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token'])) {
        return $query['token'];
      }
    }

    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      $lastPart = end($pathParts);
      return $lastPart ?: null;
    }

    return null;
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    $text = sprintf(
      "📊 Статистика расхода трафика\n\nИзрасходовано всего: %s ГБ\nВсего по тарифу: %s ГБ\nОсталось: %s ГБ\nИспользование тарифа: %.2f%%",
      $this->transferToGBString($transferUsed),
      $this->transferToGBString($transferTotal),
      $this->transferToGBString($transferRemaining),
      $usagePercentage
    );

    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $subscribeUrl = Helper::getSubscribeUrl($user->token);
    $text = sprintf("🔗 Ваша персональная ссылка на подписку (никому не передавайте):\n\n`%s`", $subscribeUrl);

    $this->sendMessage($msg, $text);
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $user->telegram_id = null;
    if (!$user->save()) {
      $this->sendMessage($msg, '❌  Ошибка при отвязке на сервере, повторите позже.');
      return;
    }

    $this->sendMessage($msg, '✅  Telegram успешно отвязан от аккаунта XBoard.');
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    if (!isset($matches[2]) || !is_numeric($matches[2])) {
      Log::warning('Telegram: при ответе на тикет не распознан ID тикета по регулярке', ['matches' => $matches, 'msg' => $msg]);
      $this->sendMessage($msg, '❌  Не удалось определить ID тикета. Отвечайте прямо на сообщение-напоминание «📮 Напоминание о тикете #N».');
      return;
    }

    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) {
      $this->sendMessage($msg, "❌  Тикет #{$ticketId} не найден в базе (возможно, уже удалён).");
      return;
    }

    $ticketService = new TicketService();
    $ticketService->replyByAdmin(
      $ticketId,
      $msg->text,
      $user->id
    );

    $this->sendMessage($msg, "✅  Ответ на тикет #{$ticketId} успешно отправлен.");
  }

  /**
   * Добавить команды бота из конфига в общий список (registerBotCommands)
   */
  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = [
        'command' => $command,
        'description' => $config['description']
      ];
    }

    return $commands;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }

}
