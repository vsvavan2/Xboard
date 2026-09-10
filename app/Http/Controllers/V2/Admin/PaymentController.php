<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];

        $pluginMethods = PaymentService::getAllPaymentMethodNames();
        $methods = array_merge($methods, $pluginMethods);

        return $this->success(array_unique($methods));
    }

    public function fetch()
    {
        $payments = Payment::orderBy('sort', 'ASC')->get()->makeVisible('config');
        foreach ($payments as $k => $v) {
            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = $v->notify_domain . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return $this->success($payments);
    }

    public function getPaymentForm(Request $request)
    {
        try {
            $paymentService = new PaymentService($request->input('payment'), $request->input('id'));
            return $this->success(collect($paymentService->form()));
        } catch (\Exception $e) {
            return $this->fail([400, '支付方式不存在或未启用']);
        }
    }

    public function show(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);
        $payment->enable = !$payment->enable;
        if (!$payment->save())
            return $this->fail([500, '保存失败']);
        return $this->success(true);
    }

    public function save(Request $request)
    {
        if (!admin_setting('app_url')) {
            return $this->fail([400, '请在站点配置中配置站点地址']);
        }
        $params = $request->validate([
            'name' => 'required',
            'icon' => 'nullable',
            'payment' => 'required',
            'config' => 'required',
            'notify_domain' => 'nullable|url',
            'handling_fee_fixed' => 'nullable|integer',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => '显示名称不能为空',
            'payment.required' => '网关参数不能为空',
            'config.required' => '配置参数不能为空',
            'notify_domain.url' => '自定义通知域名格式有误',
            'handling_fee_fixed.integer' => '固定手续费格式有误',
            'handling_fee_percent.between' => '百分比手续费范围须在0-100之间'
        ]);

        // Custom gateway-side config validation (human-readable RU errors)
        $gateway = (string) ($params['payment'] ?? '');
        $cfg = is_string($params['config'] ?? null) ? @json_decode($params['config'], true) : $params['config'];
        if (!is_array($cfg)) {
            return $this->fail([400, 'Конфигурация шлюза: некорректный JSON формат']);
        }
        if ($gateway === 'yookassa') {
            $shopId = trim((string) ($cfg['shop_id'] ?? ''));
            $secret = trim((string) ($cfg['secret_key'] ?? ''));
            $sendReceipt = !empty($cfg['send_receipt']);
            if ($shopId === '' || !preg_match('/^\d{4,12}$/', $shopId)) {
                return $this->fail([400, 'YooKassa: «Shop ID ЮKassa» — введите цифры (4-12 знаков), возьмите в ЛК ЮKassa']);
            }
            if ($secret === '' || !preg_match('/^(test_|live_)[A-Za-z0-9_-]{20,}$/', $secret)) {
                return $this->fail([400, 'YooKassa: «Секретный ключ (Bearer)» — должен начинаться с test_ или live_ (возьмите в ЛК)']);
            }
            if ($sendReceipt) {
                $tax = trim((string) ($cfg['tax_system_code'] ?? ''));
                $vat = trim((string) ($cfg['vat_code'] ?? ''));
                if ($tax === '' || !preg_match('/^[1-6]$/', $tax)) {
                    return $this->fail([400, 'YooKassa: «Код СНО» — при включенной кассе 54-ФЗ введите цифру 1..6 (см. описание под полем)']);
                }
                if ($vat === '' || !preg_match('/^[1-6]$/', $vat)) {
                    return $this->fail([400, 'YooKassa: «Ставка НДС» — при включенной кассе 54-ФЗ введите цифру 1..6 (обычно 1 = без НДС)']);
                }
            }
            $locale = trim((string) ($cfg['locale'] ?? ''));
            if ($locale !== '' && !in_array($locale, ['ru-RU', 'en-US'], true)) {
                return $this->fail([400, 'YooKassa: «Язык формы» — только ru-RU или en-US']);
            }
            $params['config'] = $cfg;
        }
        if ($request->input('id')) {
            $payment = Payment::find($request->input('id'));
            if (!$payment)
                return $this->fail([400202, '支付方式不存在']);
            try {
                $payment->update($params);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
            return $this->success(true);
        }
        $params['uuid'] = Helper::randomChar(8);
        if (!Payment::create($params)) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);
        return $this->success($payment->delete());
    }


    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                if (!Payment::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }
}
