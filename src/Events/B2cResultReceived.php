<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Events;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a B2C (Business-to-Customer) result callback is received.
 *
 * This callback arrives after a B2C payment request completes — successfully
 * or otherwise. ResultCode 0 means the payment was disbursed.
 */
class B2cResultReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly WebhookLog $log,
        public readonly array $result,
        public readonly bool $successful,
    ) {}

    public function wasSuccessful(): bool
    {
        return $this->successful;
    }

    public function conversationId(): string
    {
        return (string) ($this->result['ConversationID'] ?? '');
    }

    public function originatorConversationId(): string
    {
        return (string) ($this->result['OriginatorConversationID'] ?? '');
    }

    public function transactionId(): string
    {
        return (string) ($this->result['TransactionID'] ?? '');
    }

    /**
     * Extracts a named entry from ResultParameters.ResultParameter.
     */
    public function resultParameter(string $name): mixed
    {
        $params = $this->result['ResultParameters']['ResultParameter'] ?? [];

        foreach ($params as $param) {
            if (($param['Key'] ?? '') === $name) {
                return $param['Value'] ?? null;
            }
        }

        return null;
    }

    public function amount(): ?float
    {
        $value = $this->resultParameter('TransactionAmount');
        return $value !== null ? (float) $value : null;
    }

    public function receiptNumber(): ?string
    {
        $value = $this->resultParameter('TransactionReceipt');
        return $value !== null ? (string) $value : null;
    }
}
