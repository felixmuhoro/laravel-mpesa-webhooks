<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Persists every inbound M-Pesa webhook for auditing and retry purposes.
 *
 * @property int         $id
 * @property string      $type            stk_callback | c2b_confirmation | b2c_result | unknown
 * @property array       $payload         Raw decoded JSON body
 * @property string      $idempotency_key Unique reference extracted from payload
 * @property string      $status          pending | processed | duplicate | failed | rejected
 * @property int         $attempts        Number of processing attempts
 * @property string|null $error           Last error message if status = failed
 * @property string|null $ip_address      Source IP, for audit trail
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class WebhookLog extends Model
{
    use Prunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
            'attempts'     => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('mpesa-webhooks.table', 'mpesa_webhook_logs');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeRetryable(Builder $query): Builder
    {
        $maxAttempts = config('mpesa-webhooks.retry.max_attempts', 3);

        return $query->where('status', 'failed')
                     ->where('attempts', '<', $maxAttempts);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function markProcessed(): bool
    {
        return $this->update([
            'status'       => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): bool
    {
        return $this->update([
            'status'   => 'failed',
            'attempts' => $this->attempts + 1,
            'error'    => $error,
        ]);
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    // -------------------------------------------------------------------------
    // Prunable
    // -------------------------------------------------------------------------

    public function prunable(): Builder
    {
        $days = config('mpesa-webhooks.prune_after_days');

        if ($days === null) {
            // Return a query that matches nothing when pruning is disabled
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->where('status', 'processed')
            ->where('created_at', '<', now()->subDays((int) $days));
    }
}
