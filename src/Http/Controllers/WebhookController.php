<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Http\Controllers;

use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use FelixMuhoro\MpesaWebhooks\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Handles inbound M-Pesa webhook HTTP requests.
 *
 * Each action corresponds to a distinct Safaricom callback URL:
 *   POST /mpesa/webhook/stk   — STK Push result URL
 *   POST /mpesa/webhook/c2b   — C2B Confirmation URL
 *   POST /mpesa/webhook/b2c   — B2C Result URL
 *
 * The three routes are intentionally separate so that each can be registered
 * independently in the Daraja portal. Internally they all fan into the same
 * WebhookProcessor pipeline.
 *
 * The dashboard action is a GET that renders a paginated view of recent logs.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {}

    // -------------------------------------------------------------------------
    // Webhook endpoints
    // -------------------------------------------------------------------------

    public function stk(Request $request): JsonResponse
    {
        return $this->handle($request);
    }

    public function c2b(Request $request): JsonResponse
    {
        return $this->handle($request);
    }

    public function b2c(Request $request): JsonResponse
    {
        return $this->handle($request);
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function dashboard(Request $request): \Illuminate\View\View
    {
        $perPage = (int) config('mpesa-webhooks.dashboard.per_page', 50);

        $logs = WebhookLog::query()
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('idempotency_key', 'like', "%{$search}%")
                          ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $stats = [
            'total'     => WebhookLog::count(),
            'processed' => WebhookLog::where('status', 'processed')->count(),
            'failed'    => WebhookLog::where('status', 'failed')->count(),
            'pending'   => WebhookLog::where('status', 'pending')->count(),
        ];

        return view('mpesa-webhooks::dashboard', compact('logs', 'stats'));
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function handle(Request $request): JsonResponse
    {
        $result = $this->processor->process($request);

        if ($result->isRejected()) {
            // Return 403 — Safaricom will not retry on 4xx, which is what we
            // want for IP/signature failures (noise, not legitimate failures).
            return response()->json(
                ['ResultCode' => 'C2B00012', 'ResultDesc' => 'Rejected'],
                403,
            );
        }

        // Return Safaricom's expected acknowledgement shape.
        // Always 200 for processed AND duplicate — non-200 triggers retries.
        return response()->json([
            'ResultCode' => '0',
            'ResultDesc' => 'Accepted',
        ]);
    }
}
