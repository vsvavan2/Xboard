<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            Log::info('[Payment] уведомление от платёжной системы: метод=' . $method . ' uuid=' . $uuid
                . ' параметры=[' . implode(', ', array_keys($request->input())) . ']');
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                Log::warning('[Payment] уведомление отклонено (не пройдена проверка подписи): метод=' . $method . ' uuid=' . $uuid);
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                Log::warning('[Payment] не удалось обработать заказ: trade_no=' . ($verify['trade_no'] ?? '?'));
                return $this->fail([400, 'handle error']);
            }
            Log::info('[Payment] уведомление обработано успешно: trade_no=' . ($verify['trade_no'] ?? '?'));
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error('[Payment] ошибка обработки уведомления: ' . $e->getMessage());
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('[Payment] заказ не найден по trade_no=' . $tradeNo);
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING) {
            Log::info('[Payment] заказ уже обработан (status=' . $order->status . '), trade_no=' . $tradeNo);
            return true;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            Log::error('[Payment] OrderService::paid() вернул ошибку, trade_no=' . $tradeNo);
            return false;
        }

        Log::info('[Payment] заказ оплачен: trade_no=' . $tradeNo . ' callback_no=' . $callbackNo);
        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
