<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks\Tests;

use FelixMuhoro\MpesaWebhooks\Events\B2cResultReceived;
use FelixMuhoro\MpesaWebhooks\Events\C2bConfirmationReceived;
use FelixMuhoro\MpesaWebhooks\Events\StkCallbackReceived;
use FelixMuhoro\MpesaWebhooks\Events\WebhookReceived;
use FelixMuhoro\MpesaWebhooks\Models\WebhookLog;
use FelixMuhoro\MpesaWebhooks\MpesaWebhooksServiceProvider;
use FelixMuhoro\MpesaWebhooks\WebhookProcessor;
use FelixMuhoro\MpesaWebhooks\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class WebhookProcessorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MpesaWebhooksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('mpesa-webhooks.ip_verification.enabled', false);
        $app['config']->set('mpesa-webhooks.signature.enabled', false);
        $app['config']->set('mpesa-webhooks.idempotency.reject_duplicates', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(array $payload, string $ip = '196.201.214.200'): Request
    {
        $json    = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create('/', 'POST', [], [], [], [], $json);
        $request->headers->set('Content-Type', 'application/json');
        $request->server->set('REMOTE_ADDR', $ip);
        return $request;
    }

    private function stkPayload(int $resultCode = 0, string $checkoutId = 'ws_CO_01012024_test'): array
    {
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'mrq-001',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode'        => $resultCode,
                    'ResultDesc'        => $resultCode === 0
                        ? 'The service request is processed successfully.'
                        : 'The balance is insufficient for this transaction',
                ],
            ],
        ];

        if ($resultCode === 0) {
            $payload['Body']['stkCallback']['CallbackMetadata'] = [
                'Item' => [
                    ['Name' => 'Amount',             'Value' => 500.00],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'QBH0000000'],
                    ['Name' => 'TransactionDate',    'Value' => 20240115120000],
                    ['Name' => 'PhoneNumber',        'Value' => 254712345678],
                ],
            ];
        }

        return $payload;
    }

    private function c2bPayload(string $transId = 'QBH1234567'): array
    {
        return [
            'TransactionType'   => 'Pay Bill',
            'TransID'           => $transId,
            'TransTime'         => '20240115120000',
            'TransAmount'       => '1000.00',
            'BusinessShortCode' => '174379',
            'BillRefNumber'     => 'INV-001',
            'InvoiceNumber'     => '',
            'OrgAccountBalance' => '50000.00',
            'ThirdPartyTransID' => '',
            'MSISDN'            => '254712345678',
            'FirstName'         => 'John',
            'MiddleName'        => '',
            'LastName'          => 'Doe',
        ];
    }

    private function b2cPayload(
        string $originator = 'AG_001',
        string $transId    = 'QBH9876543',
        int    $resultCode = 0,
    ): array {
        return [
            'Result' => [
                'ResultType'               => 0,
                'ResultCode'               => $resultCode,
                'ResultDesc'               => 'The service request is processed successfully.',
                'OriginatorConversationID' => $originator,
                'ConversationID'           => 'AG_20240115_001',
                'TransactionID'            => $transId,
                'ResultParameters'         => [
                    'ResultParameter' => [
                        ['Key' => 'TransactionAmount',                  'Value' => 2000.00],
                        ['Key' => 'TransactionReceipt',                 'Value' => 'QBH9876543'],
                        ['Key' => 'ReceiverPartyPublicName',            'Value' => '254712345678 - John Doe'],
                        ['Key' => 'TransactionCompletedDateTime',       'Value' => '15.01.2024 12:00:00'],
                        ['Key' => 'B2CUtilityAccountAvailableFunds',   'Value' => 98000.00],
                        ['Key' => 'B2CWorkingAccountAvailableFunds',   'Value' => 100000.00],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // STK callback tests
    // -------------------------------------------------------------------------

    public function test_successful_stk_callback_is_processed(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest($this->stkPayload()));

        $this->assertTrue($result->isProcessed());
        $this->assertSame('stk_callback', $result->event_type);
        $this->assertSame('ws_CO_01012024_test', $result->idempotency_key);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(StkCallbackReceived::class, function (StkCallbackReceived $e) {
            return $e->wasSuccessful()
                && $e->amount() === 500.0
                && $e->receiptNumber() === 'QBH0000000'
                && $e->phoneNumber() === '254712345678';
        });
    }

    public function test_failed_stk_callback_fires_event_with_unsuccessful_flag(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest($this->stkPayload(resultCode: 1032)));

        $this->assertTrue($result->isProcessed());

        Event::assertDispatched(StkCallbackReceived::class, fn (StkCallbackReceived $e) => !$e->wasSuccessful());
    }

    public function test_stk_callback_is_persisted_to_database(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $processor->process($this->makeRequest($this->stkPayload()));

        $this->assertDatabaseHas('mpesa_webhook_logs', [
            'type'            => 'stk_callback',
            'status'          => 'processed',
            'idempotency_key' => 'ws_CO_01012024_test',
        ]);
    }

    // -------------------------------------------------------------------------
    // Idempotency / deduplication
    // -------------------------------------------------------------------------

    public function test_duplicate_stk_callback_is_not_reprocessed(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $payload   = $this->stkPayload(checkoutId: 'ws_CO_UNIQUE_001');

        $first  = $processor->process($this->makeRequest($payload));
        $second = $processor->process($this->makeRequest($payload));

        $this->assertTrue($first->isProcessed());
        $this->assertTrue($second->isDuplicate());

        // Event should have been dispatched only once
        Event::assertDispatchedTimes(StkCallbackReceived::class, 1);

        // Only one log row for this key
        $this->assertSame(
            1,
            WebhookLog::where('idempotency_key', 'ws_CO_UNIQUE_001')->count(),
        );
    }

    // -------------------------------------------------------------------------
    // C2B tests
    // -------------------------------------------------------------------------

    public function test_c2b_confirmation_is_processed(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest($this->c2bPayload()));

        $this->assertTrue($result->isProcessed());
        $this->assertSame('c2b_confirmation', $result->event_type);

        Event::assertDispatched(C2bConfirmationReceived::class, function (C2bConfirmationReceived $e) {
            return $e->transactionId() === 'QBH1234567'
                && $e->amount() === 1000.0
                && $e->accountReference() === 'INV-001';
        });
    }

    // -------------------------------------------------------------------------
    // B2C tests
    // -------------------------------------------------------------------------

    public function test_b2c_result_is_processed(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest($this->b2cPayload()));

        $this->assertTrue($result->isProcessed());
        $this->assertSame('b2c_result', $result->event_type);

        Event::assertDispatched(B2cResultReceived::class, function (B2cResultReceived $e) {
            return $e->wasSuccessful()
                && $e->amount() === 2000.0
                && $e->transactionId() === 'QBH9876543';
        });
    }

    public function test_failed_b2c_result_fires_event_with_unsuccessful_flag(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest($this->b2cPayload(resultCode: 2001)));

        $this->assertTrue($result->isProcessed());
        Event::assertDispatched(B2cResultReceived::class, fn (B2cResultReceived $e) => !$e->wasSuccessful());
    }

    // -------------------------------------------------------------------------
    // Rejection tests
    // -------------------------------------------------------------------------

    public function test_invalid_json_is_rejected(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [], 'not-json');
        $request->headers->set('Content-Type', 'application/json');

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($request);

        $this->assertTrue($result->isRejected());
        $this->assertSame('unknown', $result->event_type);
    }

    public function test_ip_verification_rejects_unknown_ip(): void
    {
        $this->app['config']->set('mpesa-webhooks.ip_verification.enabled', true);
        $this->app['config']->set('mpesa-webhooks.ip_verification.allowlist', ['196.201.214.200']);

        $processor = $this->app->make(WebhookProcessor::class);
        $request   = $this->makeRequest($this->stkPayload(), ip: '1.2.3.4');

        $result = $processor->process($request);

        $this->assertTrue($result->isRejected());
    }

    public function test_ip_verification_allows_known_ip(): void
    {
        Event::fake();

        $this->app['config']->set('mpesa-webhooks.ip_verification.enabled', true);
        $this->app['config']->set('mpesa-webhooks.ip_verification.allowlist', ['196.201.214.200']);

        $processor = $this->app->make(WebhookProcessor::class);
        $request   = $this->makeRequest($this->stkPayload(), ip: '196.201.214.200');

        $result = $processor->process($request);

        $this->assertTrue($result->isProcessed());
    }

    // -------------------------------------------------------------------------
    // WebhookResult value object tests
    // -------------------------------------------------------------------------

    public function test_webhook_result_acknowledge_flags(): void
    {
        $processed = new WebhookResult(
            status: 'processed', payload: [], event_type: 'stk_callback', idempotency_key: 'x',
        );
        $duplicate = new WebhookResult(
            status: 'duplicate', payload: [], event_type: 'stk_callback', idempotency_key: 'x',
        );
        $failed = new WebhookResult(
            status: 'failed', payload: [], event_type: 'stk_callback', idempotency_key: 'x',
        );

        $this->assertTrue($processed->shouldAcknowledge());
        $this->assertTrue($duplicate->shouldAcknowledge());
        $this->assertFalse($failed->shouldAcknowledge());
    }

    // -------------------------------------------------------------------------
    // Unknown type fallback
    // -------------------------------------------------------------------------

    public function test_unknown_webhook_type_is_logged_but_no_typed_event_fires(): void
    {
        Event::fake();

        $processor = $this->app->make(WebhookProcessor::class);
        $result    = $processor->process($this->makeRequest(['RandomKey' => 'RandomValue']));

        $this->assertTrue($result->isProcessed());
        $this->assertSame('unknown', $result->event_type);
        $this->assertNull($result->idempotency_key);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(StkCallbackReceived::class);
        Event::assertNotDispatched(C2bConfirmationReceived::class);
        Event::assertNotDispatched(B2cResultReceived::class);
    }
}
