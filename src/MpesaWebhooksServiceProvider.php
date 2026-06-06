<?php

declare(strict_types=1);

namespace FelixMuhoro\MpesaWebhooks;

use FelixMuhoro\MpesaWebhooks\Console\Commands\RetryFailedWebhooks;
use FelixMuhoro\MpesaWebhooks\Verifiers\IpVerifier;
use FelixMuhoro\MpesaWebhooks\Verifiers\SignatureVerifier;
use Illuminate\Support\ServiceProvider;

class MpesaWebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mpesa-webhooks.php',
            'mpesa-webhooks',
        );

        $this->app->singleton(IpVerifier::class, function ($app) {
            $config = config('mpesa-webhooks.ip_verification');
            return new IpVerifier($config['allowlist'] ?? []);
        });

        $this->app->singleton(SignatureVerifier::class, function ($app) {
            $config = config('mpesa-webhooks.signature');
            return new SignatureVerifier(
                secret:     $config['secret'] ?? '',
                headerName: $config['header'] ?? 'X-Mpesa-Signature',
                algorithm:  $config['algorithm'] ?? 'sha256',
            );
        });

        $this->app->singleton(WebhookProcessor::class, function ($app) {
            return new WebhookProcessor(
                ipVerifier:        $app->make(IpVerifier::class),
                signatureVerifier: $app->make(SignatureVerifier::class),
                config:            config('mpesa-webhooks'),
            );
        });
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerCommands();
        $this->registerMigrations();
    }

    private function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/mpesa-webhooks.php' => config_path('mpesa-webhooks.php'),
        ], 'mpesa-webhooks-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'mpesa-webhooks-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/mpesa-webhooks'),
        ], 'mpesa-webhooks-views');
    }

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mpesa-webhooks');
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RetryFailedWebhooks::class]);
        }
    }

    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
