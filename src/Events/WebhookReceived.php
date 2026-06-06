<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Events;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired for every successfully verified and deduplicated webhook, regardless
 * of type. Listeners that care about all incoming M-Pesa events (e.g. an
 * audit log) should hook into this event.
 *
 * Type-specific events (StkCallbackReceived, etc.) are fired *in addition* to
 * this one, not instead of it.
 */
class WebhookReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly WebhookLog $log,
        public readonly string $webhookType,
        public readonly array $payload,
    ) {}
}
