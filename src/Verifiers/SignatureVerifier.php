<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Verifiers;

use Illuminate\Http\Request;

/**
 * Verifies an HMAC signature on incoming webhook payloads.
 *
 * Safaricom's Daraja v1 API does not currently sign callbacks, but this
 * verifier supports two real-world use cases:
 *
 *   1. You are running your own proxy / relay that signs payloads before
 *      forwarding them to Laravel (useful in multi-tenant setups).
 *   2. A future Daraja version adds callback signatures.
 *
 * The expected signature is computed as:
 *   HMAC-{algorithm}(raw_request_body, shared_secret)
 * and must be delivered in the header configured under
 * `mpesa-webhooks.signature.header`.
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly string $headerName,
        private readonly string $algorithm = 'sha256',
    ) {}

    public function verify(Request $request): bool
    {
        $providedSignature = $request->header($this->headerName);

        if ($providedSignature === null || $providedSignature === '') {
            return false;
        }

        $rawBody  = $request->getContent();
        $expected = hash_hmac($this->algorithm, $rawBody, $this->secret);

        // Constant-time comparison to prevent timing attacks.
        return hash_equals($expected, strtolower($providedSignature));
    }
}
