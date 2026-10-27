<?php

use Illuminate\Support\Facades\Route;

/*
| Возврат пользователя после оплаты по «чистому» адресу (без «#»).
|
| ЮMoney в документации описывает successURL как «URL path», поэтому
| платёжная ссылка ведёт на обычный путь /pay/return/{trade_no}, а он уже
| переадресует браузер на hash-маршрут личного кабинета /#/order/{trade_no}.
| За счёт этого параметр платёжки соответствует документации, а страница
| заказа в панели открывается как раньше.
*/
Route::get('/pay/return/{trade_no}', function (string $tradeNo) {
    return redirect('/#/order/' . $tradeNo, 302);
})->where('trade_no', '[A-Za-z0-9_-]{1,64}');
