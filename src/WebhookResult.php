<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks;

/**
 * Immutable value object returned by WebhookProcessor::process().
 *
 * @see WebhookProcessor
 */
final readonly class WebhookResult
{
    public function __construct(
        /** One of: 'processed', 'duplicate', 'failed', 'rejected' */
        public string $status,

        /** Decoded webhook payload, or empty array on hard rejection */
        public array $payload,

        /** Webhook type: 'stk_callback', 'c2b_confirmation', 'b2c_result', 'unknown' */
        public string $event_type,

        /**
         * The idempotency key used to deduplicate this webhook.
         * Null only when the payload contained no recognisable reference.
         */
        public ?string $idempotency_key,

        /** Human-readable message — useful for debugging */
        public string $message = '',
    ) {}

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Whether the HTTP layer should respond with 200 OK.
     * Duplicates also get 200 — Safaricom will keep retrying if you return non-200.
     */
    public function shouldAcknowledge(): bool
    {
        return in_array($this->status, ['processed', 'duplicate'], true);
    }

    public function toArray(): array
    {
        return [
            'status'          => $this->status,
            'event_type'      => $this->event_type,
            'idempotency_key' => $this->idempotency_key,
            'message'         => $this->message,
        ];
    }
}
