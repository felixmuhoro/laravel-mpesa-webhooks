<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Events;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an STK Push callback is received and verified.
 *
 * The $stkCallback array is the contents of Body.stkCallback from the raw
 * Safaricom payload. A ResultCode of 0 indicates a successful payment.
 *
 * Example structure:
 *   [
 *     'MerchantRequestID'  => 'abc-123',
 *     'CheckoutRequestID'  => 'ws_CO_...',
 *     'ResultCode'         => 0,
 *     'ResultDesc'         => 'The service request is processed successfully.',
 *     'CallbackMetadata'   => [
 *       'Item' => [
 *         ['Name' => 'Amount',              'Value' => 100.00],
 *         ['Name' => 'MpesaReceiptNumber',  'Value' => 'QBH...' ],
 *         ['Name' => 'TransactionDate',     'Value' => 20240115120000],
 *         ['Name' => 'PhoneNumber',         'Value' => 2547XXXXXXXX],
 *       ],
 *     ],
 *   ]
 */
class StkCallbackReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly WebhookLog $log,
        public readonly array $stkCallback,
        public readonly bool $successful,
    ) {}

    /**
     * Whether the STK Push completed with a successful payment.
     */
    public function wasSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Extracts a named item from CallbackMetadata.
     * Returns null if the metadata is absent (failed transactions have none).
     */
    public function metadataItem(string $name): mixed
    {
        $items = $this->stkCallback['CallbackMetadata']['Item'] ?? [];

        foreach ($items as $item) {
            if (($item['Name'] ?? '') === $name) {
                return $item['Value'] ?? null;
            }
        }

        return null;
    }

    public function amount(): ?float
    {
        $value = $this->metadataItem('Amount');
        return $value !== null ? (float) $value : null;
    }

    public function receiptNumber(): ?string
    {
        $value = $this->metadataItem('MpesaReceiptNumber');
        return $value !== null ? (string) $value : null;
    }

    public function phoneNumber(): ?string
    {
        $value = $this->metadataItem('PhoneNumber');
        return $value !== null ? (string) $value : null;
    }
}
