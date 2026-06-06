<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Route Prefix
    |--------------------------------------------------------------------------
    |
    | All webhook routes will be prefixed with this string. Change it if
    | your application already uses /mpesa for something else.
    |
    */
    'route_prefix' => env('MPESA_WEBHOOK_ROUTE_PREFIX', 'mpesa/webhook'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all incoming webhook routes. The package's own
    | VerifyMpesaWebhook middleware is always applied internally — add any
    | additional middleware here (e.g. throttle, logging).
    |
    */
    'route_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | IP Verification
    |--------------------------------------------------------------------------
    |
    | Safaricom publishes a fixed set of egress IPs for production callbacks.
    | Set 'enabled' to true to reject requests from unlisted IPs.
    |
    | For sandbox development set 'enabled' to false or add 127.0.0.1.
    |
    */
    'ip_verification' => [
        'enabled' => env('MPESA_WEBHOOK_VERIFY_IP', true),

        // Safaricom production egress IPs (as of 2024 — verify in Daraja portal)
        'allowlist' => array_filter(array_map(
            'trim',
            explode(',', env('MPESA_WEBHOOK_IP_ALLOWLIST', implode(',', [
                '196.201.214.200',
                '196.201.214.206',
                '196.201.213.114',
                '196.201.214.207',
                '196.201.214.208',
                '196.201.213.44',
                '196.201.212.127',
                '196.201.212.138',
                '196.201.212.129',
                '196.201.212.136',
                '196.201.212.74',
                '196.201.212.69',
            ])))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared Secret Signature Verification
    |--------------------------------------------------------------------------
    |
    | If Safaricom ever exposes a signature header (or you are routing through
    | your own proxy that signs payloads), enable this and set the secret.
    |
    | The signature is expected as an HMAC-SHA256 hex digest of the raw request
    | body, delivered in the header named below.
    |
    */
    'signature' => [
        'enabled'    => env('MPESA_WEBHOOK_VERIFY_SIGNATURE', false),
        'secret'     => env('MPESA_WEBHOOK_SECRET', ''),
        'header'     => env('MPESA_WEBHOOK_SIGNATURE_HEADER', 'X-Mpesa-Signature'),
        'algorithm'  => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Safaricom can send the same callback more than once (especially on STK).
    | The processor deduplicates by idempotency key before dispatching events.
    |
    | 'reject_duplicates' — return a 200 immediately for already-processed keys.
    |
    */
    'idempotency' => [
        'reject_duplicates' => env('MPESA_WEBHOOK_REJECT_DUPLICATES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | The RetryFailedWebhooks command re-queues webhook log entries whose
    | status is 'failed'. Configure the maximum number of attempts and the
    | back-off strategy here.
    |
    */
    'retry' => [
        'max_attempts' => env('MPESA_WEBHOOK_MAX_ATTEMPTS', 3),
        // Seconds to wait between retries (multiplied by attempt number)
        'backoff_base' => env('MPESA_WEBHOOK_BACKOFF_BASE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table
    |--------------------------------------------------------------------------
    */
    'table' => env('MPESA_WEBHOOK_TABLE', 'mpesa_webhook_logs'),

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Automatically prune processed webhook logs older than this many days.
    | Set to null to disable pruning.
    |
    */
    'prune_after_days' => env('MPESA_WEBHOOK_PRUNE_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The built-in dashboard is a simple Blade view served at
    | {route_prefix}/dashboard. Protect it with your application's auth
    | middleware — by default it requires the 'web' guard.
    |
    */
    'dashboard' => [
        'enabled'    => env('MPESA_WEBHOOK_DASHBOARD', true),
        'middleware' => ['web', 'auth'],
        'per_page'   => 50,
    ],

];
