<?php

use Illuminate\Support\Facades\Route;
use Plugin\OlcRTC\Controllers\OlcRTCController;

// Subscribe endpoint (доступен без Auth — по ?token= в URL для клиентских приложений)
Route::get('/api/v1/user/olcrtc/sub', [OlcRTCController::class, 'subscribe']);

// Личный кабинет пользователя - требуется User middleware
Route::group([
    'prefix'     => 'api/v1/user/olcrtc',
    'middleware' => ['auth:sanctum', 'user'],
], function () {
    Route::get('/',      [OlcRTCController::class, 'info']);
    Route::get('/yaml',  [OlcRTCController::class, 'yaml']);
});
