# laravel-mpesa-webhooks

Advanced M-Pesa webhook handling for Laravel. Signature verification, IP allowlisting, idempotency, automatic retry, structured logging, and a built-in dashboard — all in one package.

Requires [`felixmuhoro/laravel-mpesa`](https://github.com/felixmuhoro/laravel-mpesa) for the underlying Daraja API client.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ |
| Laravel | 10 / 11 / 12 / 13 |
| felixmuhoro/laravel-mpesa | ^1.2 |

## Installation

```bash
composer require felixmuhoro/laravel-mpesa-webhooks
```

Publish the config and run the migration:

```bash
php artisan vendor:publish --tag=mpesa-webhooks-config
php artisan vendor:publish --tag=mpesa-webhooks-migrations
php artisan migrate
```

## Configuration

```dotenv
# Disable during sandbox development, enable in production
MPESA_WEBHOOK_VERIFY_IP=true

# Comma-separated list overrides the default Safaricom production IPs
MPESA_WEBHOOK_IP_ALLOWLIST=196.201.214.200,196.201.214.206

# Only needed if you are proxying and signing callbacks yourself
MPESA_WEBHOOK_VERIFY_SIGNATURE=false
MPESA_WEBHOOK_SECRET=your-shared-secret

# Retry configuration
MPESA_WEBHOOK_MAX_ATTEMPTS=3
MPESA_WEBHOOK_BACKOFF_BASE=60

# Auto-prune processed logs older than N days (null = never)
MPESA_WEBHOOK_PRUNE_DAYS=90
```

## Callback URLs

Register these URLs in your Daraja portal:

| Type | URL |
|---|---|
| STK Push Result URL | `https://your-domain.com/mpesa/webhook/stk` |
| C2B Confirmation URL | `https://your-domain.com/mpesa/webhook/c2b` |
| B2C Result URL | `https://your-domain.com/mpesa/webhook/b2c` |

## Listening to Events

### STK Push

```php
use FelixMuhoro\MpesaWebhooks\Events\StkCallbackReceived;

class HandleStkCallback
{
    public function handle(StkCallbackReceived $event): void
    {
        if (! $event->wasSuccessful()) {
            $resultCode = $event->stkCallback['ResultCode'];
            $resultDesc = $event->stkCallback['ResultDesc'];
            return;
        }

        $amount     = $event->amount();
        $receipt    = $event->receiptNumber();
        $phone      = $event->phoneNumber();
        $checkoutId = $event->stkCallback['CheckoutRequestID'];

        Order::where('checkout_request_id', $checkoutId)->update([
            'status'        => 'paid',
            'mpesa_receipt' => $receipt,
            'paid_amount'   => $amount,
        ]);
    }
}
```

### C2B Confirmation

```php
use FelixMuhoro\MpesaWebhooks\Events\C2bConfirmationReceived;

class HandleC2bConfirmation
{
    public function handle(C2bConfirmationReceived $event): void
    {
        $transId   = $event->transactionId();
        $amount    = $event->amount();
        $phone     = $event->msisdn();
        $reference = $event->accountReference();
    }
}
```

### B2C Result

```php
use FelixMuhoro\MpesaWebhooks\Events\B2cResultReceived;

class HandleB2cResult
{
    public function handle(B2cResultReceived $event): void
    {
        if (! $event->wasSuccessful()) {
            return;
        }

        $receipt      = $event->receiptNumber();
        $amount       = $event->amount();
        $originatorId = $event->originatorConversationId();
    }
}
```

### Generic Event

`WebhookReceived` fires for every successfully verified and deduplicated webhook, regardless of type:

```php
use FelixMuhoro\MpesaWebhooks\Events\WebhookReceived;

class AuditWebhooks
{
    public function handle(WebhookReceived $event): void
    {
        // $event->log         — WebhookLog model
        // $event->webhookType — 'stk_callback' | 'c2b_confirmation' | 'b2c_result' | 'unknown'
        // $event->payload
    }
}
```

## Idempotency

Safaricom resends the same callback if your endpoint does not respond with HTTP 200 quickly enough. The package deduplicates by idempotency key:

- **STK**: `CheckoutRequestID`
- **C2B**: `TransID`
- **B2C**: `OriginatorConversationID:TransactionID`

A duplicate webhook returns HTTP 200 immediately so Safaricom stops retrying, but does not re-fire events or re-process.

## Retry Failed Webhooks

```bash
# Retry all retryable failures (respects back-off)
php artisan mpesa:retry-webhooks

# Retry a specific log entry
php artisan mpesa:retry-webhooks --id=42

# Filter by type
php artisan mpesa:retry-webhooks --type=stk_callback

# Override back-off and retry immediately
php artisan mpesa:retry-webhooks --force

# Limit batch size
php artisan mpesa:retry-webhooks --limit=10
```

Add to your scheduler for automatic recovery:

```php
$schedule->command('mpesa:retry-webhooks')->everyFiveMinutes();
```

## Dashboard

Visit `/mpesa/webhook/dashboard` (requires auth by default).

Shows all inbound webhooks with filtering by type and status, idempotency key, source IP, attempt count, and error messages.

```php
// config/mpesa-webhooks.php
'dashboard' => [
    'enabled'    => true,
    'middleware' => ['web', 'auth:admin'],
    'per_page'   => 50,
],
```

## WebhookLog Model

```php
use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;

WebhookLog::failed()->get();
WebhookLog::processed()->get();
WebhookLog::pending()->get();
WebhookLog::retryable()->get();
WebhookLog::byType('stk_callback')->latest()->get();
```

## Security

- IP allowlisting is enabled by default and checks against Safaricom's published egress IPs.
- Signature verification is opt-in for proxy setups.
- Rejected requests (wrong IP, bad signature, unparseable body) return HTTP 403.
- IP checks respect Laravel's `TrustProxies` configuration.

## Testing

```bash
composer test
```

Uses in-memory SQLite and Orchestra Testbench — no external services required.

## License

MIT
