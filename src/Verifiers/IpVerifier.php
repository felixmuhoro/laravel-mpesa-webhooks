<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Verifiers;

use Illuminate\Http\Request;

/**
 * Guards the webhook endpoint against requests originating outside Safaricom's
 * known egress IP ranges.
 *
 * Safaricom routes callbacks through a small, fixed set of IP addresses.
 * Blocking anything else adds a cheap layer of defence even if your endpoint
 * URL is somehow discovered by a third party.
 *
 * Note: this check is done against the *connecting* IP. If your application
 * sits behind a reverse proxy (e.g. nginx, Cloudflare) make sure
 * `TrustProxies` is configured so that $request->ip() returns the real
 * client IP, not 127.0.0.1.
 */
final class IpVerifier
{
    /** @param list<string> $allowlist */
    public function __construct(
        private readonly array $allowlist,
    ) {}

    public function verify(Request $request): bool
    {
        $ip = $request->ip();

        if ($ip === null) {
            return false;
        }

        return in_array($ip, $this->allowlist, true);
    }

    /**
     * Returns the IP that was checked, for logging purposes.
     */
    public function clientIp(Request $request): ?string
    {
        return $request->ip();
    }
}
