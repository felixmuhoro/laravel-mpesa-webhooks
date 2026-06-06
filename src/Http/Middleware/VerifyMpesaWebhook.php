<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the request body is preserved as a raw string before any framework
 * middleware converts it to an array (which would break signature computation).
 *
 * Also sets a flag so the WebhookController knows the request passed through
 * the verification layer — a useful sanity check for custom route definitions.
 *
 * The actual IP and signature checks live in WebhookProcessor so they can be
 * reused outside of HTTP context (e.g. tests, queue jobs).
 */
class VerifyMpesaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // Force the framework to read and store the raw content before any
        // subsequent middleware or controller accesses $request->all().
        $request->getContent();

        // Mark request so controllers can assert the middleware ran.
        $request->attributes->set('mpesa_webhook_verified', true);

        return $next($request);
    }
}
