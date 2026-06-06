<?php

declare(strict_types=1);

use FelixMuhoro\MpesaWebhooks\Http\Controllers\WebhookController;
use FelixMuhoro\MpesaWebhooks\Http\Middleware\VerifyMpesaWebhook;
use Illuminate\Support\Facades\Route;

$prefix     = config('mpesa-webhooks.route_prefix', 'mpesa/webhook');
$middleware = array_merge(
    ['api'],
    config('mpesa-webhooks.route_middleware', []),
    [VerifyMpesaWebhook::class],
);

// -------------------------------------------------------------------------
// Webhook receiver endpoints
// -------------------------------------------------------------------------
Route::prefix($prefix)
    ->middleware($middleware)
    ->name('mpesa.webhook.')
    ->group(function () {
        Route::post('stk', [WebhookController::class, 'stk'])->name('stk');
        Route::post('c2b', [WebhookController::class, 'c2b'])->name('c2b');
        Route::post('b2c', [WebhookController::class, 'b2c'])->name('b2c');
    });

// -------------------------------------------------------------------------
// Dashboard (separate middleware — web + auth)
// -------------------------------------------------------------------------
if (config('mpesa-webhooks.dashboard.enabled', true)) {
    $dashboardMiddleware = config('mpesa-webhooks.dashboard.middleware', ['web', 'auth']);

    Route::get("{$prefix}/dashboard", [WebhookController::class, 'dashboard'])
        ->middleware($dashboardMiddleware)
        ->name('mpesa.webhook.dashboard');
}
