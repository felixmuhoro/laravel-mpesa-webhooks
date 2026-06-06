<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Console\Commands;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use FelixMuhoro\MpesaWebhooks\WebhookProcessor;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Artisan command to retry failed webhook processing.
 *
 * Usage:
 *   php artisan mpesa:retry-webhooks
 *   php artisan mpesa:retry-webhooks --type=stk_callback
 *   php artisan mpesa:retry-webhooks --limit=10
 *   php artisan mpesa:retry-webhooks --id=42
 *
 * The command re-creates a synthetic HTTP Request from the stored payload and
 * runs it back through the full WebhookProcessor pipeline (sans IP/signature
 * checks, which are bypassed since we trust our own stored data).
 *
 * Back-off: a failed log is only retried if its last update was at least
 *   (backoff_base x attempts) seconds ago, preventing hot-retry loops.
 */
class RetryFailedWebhooks extends Command
{
    protected $signature = 'mpesa:retry-webhooks
                            {--id= : Retry a single webhook log by ID}
                            {--type= : Filter by webhook type (stk_callback, c2b_confirmation, b2c_result)}
                            {--limit=50 : Maximum number of records to retry}
                            {--force : Skip back-off check and retry immediately}';

    protected $description = 'Retry failed M-Pesa webhook log entries';

    public function handle(WebhookProcessor $processor): int
    {
        if ($id = $this->option('id')) {
            return $this->retrySingle((int) $id, $processor);
        }

        return $this->retryBatch($processor);
    }

    private function retrySingle(int $id, WebhookProcessor $processor): int
    {
        $log = WebhookLog::find($id);

        if ($log === null) {
            $this->error("Webhook log #{$id} not found.");
            return self::FAILURE;
        }

        $this->retryLog($log, $processor);
        return self::SUCCESS;
    }

    private function retryBatch(WebhookProcessor $processor): int
    {
        $limit = (int) ($this->option('limit') ?? 50);
        $type  = $this->option('type');

        $query = WebhookLog::retryable();

        if ($type !== null) {
            $query->where('type', $type);
        }

        if (!$this->option('force')) {
            $backoffBase = (int) config('mpesa-webhooks.retry.backoff_base', 60);

            // Only retry logs that have passed their back-off window.
            // Uses SQLite-compatible syntax; swap for MySQL DATE_SUB if needed.
            $query->where(function ($q) use ($backoffBase) {
                $q->whereRaw("updated_at <= datetime('now', '-' || (attempts * ?) || ' seconds')", [$backoffBase])
                  ->orWhereNull('updated_at');
            });
        }

        $logs = $query->oldest('updated_at')->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->info('No retryable webhooks found.');
            return self::SUCCESS;
        }

        $this->info("Retrying {$logs->count()} webhook(s)...");

        $bar = $this->output->createProgressBar($logs->count());
        $bar->start();

        foreach ($logs as $log) {
            $this->retryLog($log, $processor);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function retryLog(WebhookLog $log, WebhookProcessor $processor): void
    {
        $request = $this->buildRequest($log);

        // Temporarily disable guards — we already validated these when the
        // webhook first arrived and we trust our own DB.
        $origIp  = config('mpesa-webhooks.ip_verification.enabled');
        $origSig = config('mpesa-webhooks.signature.enabled');

        config(['mpesa-webhooks.ip_verification.enabled'     => false]);
        config(['mpesa-webhooks.signature.enabled'           => false]);
        config(['mpesa-webhooks.idempotency.reject_duplicates' => false]);

        // Reset to pending so the processor does not skip it.
        $log->update(['status' => 'pending']);

        try {
            $result = $processor->process($request);

            if ($result->isProcessed()) {
                $this->line("  <info>OK</info> Log #{$log->id} ({$log->type}) processed successfully");
            } else {
                $this->line("  <comment>FAIL</comment> Log #{$log->id} ({$log->type}) — {$result->message}");
            }
        } finally {
            config(['mpesa-webhooks.ip_verification.enabled'       => $origIp]);
            config(['mpesa-webhooks.signature.enabled'             => $origSig]);
            config(['mpesa-webhooks.idempotency.reject_duplicates' => true]);
        }
    }

    private function buildRequest(WebhookLog $log): Request
    {
        $json = json_encode($log->payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            uri:     '/',
            method:  'POST',
            content: $json,
        );

        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');

        return $request;
    }
}
