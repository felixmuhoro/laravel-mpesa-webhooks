<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Events;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a C2B (Customer-to-Business) Confirmation is received.
 *
 * Safaricom sends this after a customer pays via Paybill or Buy Goods.
 * The confirmation URL must be registered via the C2B Register URL API.
 *
 * Note: Safaricom also sends a Validation request before the confirmation.
 * The validation endpoint is intentionally separate and not handled here —
 * most integrations auto-approve validation.
 */
class C2bConfirmationReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly WebhookLog $log,
        public readonly array $payload,
    ) {}

    public function transactionId(): string
    {
        return (string) ($this->payload['TransID'] ?? '');
    }

    public function amount(): float
    {
        return (float) ($this->payload['TransAmount'] ?? 0);
    }

    public function msisdn(): string
    {
        return (string) ($this->payload['MSISDN'] ?? '');
    }

    /**
     * The account reference / bill reference number supplied by the customer.
     */
    public function accountReference(): string
    {
        return (string) ($this->payload['BillRefNumber'] ?? '');
    }

    public function shortcode(): string
    {
        return (string) ($this->payload['BusinessShortCode'] ?? '');
    }
}
