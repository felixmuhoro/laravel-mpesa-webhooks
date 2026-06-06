<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('mpesa-webhooks.table', 'mpesa_webhook_logs');

        Schema::create($table, function (Blueprint $table) {
            $table->id();

            $table->string('type', 50)->index();
            // status: pending | processed | duplicate | failed | rejected
            $table->string('status', 20)->default('pending')->index();

            // Full raw JSON body
            $table->json('payload');

            // Unique reference extracted from the payload (CheckoutRequestID,
            // TransID, etc.). Used for deduplication.
            $table->string('idempotency_key', 255)->nullable()->unique();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();

            // The source IP — useful for auditing and debugging allowlist issues.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Compound index for the retry query: WHERE status = 'failed' AND attempts < N
            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mpesa-webhooks.table', 'mpesa_webhook_logs'));
    }
};
