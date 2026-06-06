<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks;

use FelixMuhoro\MpesaWebhooks\Events\B2cResultReceived;
use FelixMuhoro\MpesaWebhooks\Events\C2bConfirmationReceived;
use FelixMuhoro\MpesaWebhooks\Events\StkCallbackReceived;
use FelixMuhoro\MpesaWebhooks\Events\WebhookReceived;
use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use FelixMuhoro\MpesaWebhooks\Verifiers\IpVerifier;
use FelixMuhoro\MpesaWebhooks\Verifiers\SignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central entry point for all inbound M-Pesa webhooks.
 *
 * Processing pipeline:
 *   1. Parse JSON body (reject unparseable payloads)
 *   2. IP verification (if enabled)
 *   3. Signature verification (if enabled)
 *   4. Detect webhook type
 *   5. Extract idempotency key
 *   6. Deduplicate — return early for already-processed keys
 *   7. Persist to webhook_logs (status = pending)
 *   8. Dispatch typed events inside a DB transaction
 *   9. Mark log as processed / failed
 *  10. Return WebhookResult
 */
class WebhookProcessor
{
    public function __construct(
        private readonly IpVerifier $ipVerifier,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly array $config,
    ) {}

    public function process(Request $request): WebhookResult
    {
        $payload = $this->parsePayload($request);

        if ($payload === null) {
            return $this->rejected('Could not parse JSON payload');
        }

        if ($this->config['ip_verification']['enabled']) {
            if (!$this->ipVerifier->verify($request)) {
                $ip = $this->ipVerifier->clientIp($request) ?? 'unknown';
                Log::warning('[mpesa-webhooks] Rejected request from unlisted IP', ['ip' => $ip]);
                return $this->rejected("IP address {$ip} is not in the allowlist");
            }
        }

        if ($this->config['signature']['enabled']) {
            if (!$this->signatureVerifier->verify($request)) {
                Log::warning('[mpesa-webhooks] Signature verification failed');
                return $this->rejected('Invalid or missing webhook signature');
            }
        }

        $type           = $this->detectType($payload);
        $idempotencyKey = $this->extractIdempotencyKey($payload, $type);

        if ($idempotencyKey !== null && $this->config['idempotency']['reject_duplicates']) {
            $existing = WebhookLog::where('idempotency_key', $idempotencyKey)
                                  ->where('status', 'processed')
                                  ->first();

            if ($existing !== null) {
                Log::info('[mpesa-webhooks] Duplicate webhook ignored', [
                    'idempotency_key' => $idempotencyKey,
                    'type'            => $type,
                    'log_id'          => $existing->id,
                ]);

                return new WebhookResult(
                    status:          'duplicate',
                    payload:         $payload,
                    event_type:      $type,
                    idempotency_key: $idempotencyKey,
                    message:         'Duplicate webhook — already processed',
                );
            }
        }

        $log = $this->createLog($request, $type, $payload, $idempotencyKey);

        return $this->dispatch($log, $type, $payload, $idempotencyKey);
    }

    private function parsePayload(Request $request): ?array
    {
        $raw = $request->getContent();

        if ($raw === '' || $raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function detectType(array $payload): string
    {
        if (isset($payload['Body']['stkCallback'])) {
            return 'stk_callback';
        }

        if (isset($payload['TransID'])) {
            return 'c2b_confirmation';
        }

        if (isset($payload['Result']['ResultType'])) {
            return 'b2c_result';
        }

        return 'unknown';
    }

    private function extractIdempotencyKey(array $payload, string $type): ?string
    {
        return match ($type) {
            'stk_callback'     => $payload['Body']['stkCallback']['CheckoutRequestID'] ?? null,
            'c2b_confirmation' => $payload['TransID'] ?? null,
            'b2c_result'       => $this->b2cIdempotencyKey($payload),
            default            => null,
        };
    }

    private function b2cIdempotencyKey(array $payload): ?string
    {
        $originator  = $payload['Result']['OriginatorConversationID'] ?? null;
        $transaction = $payload['Result']['TransactionID'] ?? null;

        if ($originator === null && $transaction === null) {
            return null;
        }

        return implode(':', array_filter([$originator, $transaction]));
    }

    private function createLog(
        Request $request,
        string $type,
        array $payload,
        ?string $idempotencyKey,
    ): WebhookLog {
        return WebhookLog::create([
            'type'            => $type,
            'payload'         => $payload,
            'idempotency_key' => $idempotencyKey,
            'status'          => 'pending',
            'attempts'        => 0,
            'ip_address'      => $request->ip(),
        ]);
    }

    private function dispatch(
        WebhookLog $log,
        string $type,
        array $payload,
        ?string $idempotencyKey,
    ): WebhookResult {
        try {
            DB::transaction(function () use ($log, $type, $payload) {
                event(new WebhookReceived($log, $type, $payload));

                match ($type) {
                    'stk_callback'     => $this->dispatchStk($log, $payload),
                    'c2b_confirmation' => $this->dispatchC2b($log, $payload),
                    'b2c_result'       => $this->dispatchB2c($log, $payload),
                    default            => null,
                };

                $log->markProcessed();
            });

            Log::info('[mpesa-webhooks] Webhook processed', [
                'type'            => $type,
                'idempotency_key' => $idempotencyKey,
                'log_id'          => $log->id,
            ]);

            return new WebhookResult(
                status:          'processed',
                payload:         $payload,
                event_type:      $type,
                idempotency_key: $idempotencyKey,
                message:         'Webhook processed successfully',
            );
        } catch (Throwable $e) {
            $log->markFailed($e->getMessage());

            Log::error('[mpesa-webhooks] Webhook processing failed', [
                'type'      => $type,
                'log_id'    => $log->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return new WebhookResult(
                status:          'failed',
                payload:         $payload,
                event_type:      $type,
                idempotency_key: $idempotencyKey,
                message:         $e->getMessage(),
            );
        }
    }

    private function dispatchStk(WebhookLog $log, array $payload): void
    {
        $callback   = $payload['Body']['stkCallback'];
        $resultCode = (int) ($callback['ResultCode'] ?? -1);

        event(new StkCallbackReceived(
            log:         $log,
            stkCallback: $callback,
            successful:  $resultCode === 0,
        ));
    }

    private function dispatchC2b(WebhookLog $log, array $payload): void
    {
        event(new C2bConfirmationReceived(log: $log, payload: $payload));
    }

    private function dispatchB2c(WebhookLog $log, array $payload): void
    {
        $result     = $payload['Result'];
        $resultCode = (int) ($result['ResultCode'] ?? -1);

        event(new B2cResultReceived(
            log:        $log,
            result:     $result,
            successful: $resultCode === 0,
        ));
    }

    private function rejected(string $message): WebhookResult
    {
        return new WebhookResult(
            status:          'rejected',
            payload:         [],
            event_type:      'unknown',
            idempotency_key: null,
            message:         $message,
        );
    }
}
