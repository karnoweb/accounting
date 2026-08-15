<?php

declare(strict_types=1);

namespace Karnoweb\Accounting;

use Illuminate\Support\ServiceProvider;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Observers\DocumentObserver;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentBuilder;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\PostingService;
use Karnoweb\Accounting\Services\ReportService;
use Karnoweb\Accounting\Services\ReversalService;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/accounting.php',
            'accounting'
        );

        $this->app->singleton(AccountService::class);
        $this->app->singleton(BalanceService::class);
        $this->app->singleton(FiscalYearService::class);
        $this->app->singleton(PostingService::class);

        $this->app->singleton(DocumentService::class, function ($app) {
            return new DocumentService(
                $app->make(BalanceService::class),
                $app->make(AccountService::class),
                $app->make(PostingService::class)
            );
        });

        // Transient: each resolve is a fresh builder (no shared line state).
        $this->app->bind(DocumentBuilder::class, function ($app) {
            return new DocumentBuilder(
                $app->make(DocumentService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton(ReportService::class, function ($app) {
            return new ReportService(
                $app->make(BalanceService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton(OpeningService::class, function ($app) {
            return new OpeningService(
                $app->make(DocumentService::class),
                $app->make(FiscalYearService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton(ClosingService::class, function ($app) {
            return new ClosingService(
                $app->make(DocumentService::class),
                $app->make(FiscalYearService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton(ReversalService::class, function ($app) {
            return new ReversalService(
                $app->make(DocumentService::class)
            );
        });

        $this->app->singleton('accounting', function ($app) {
            return new AccountingManager(
                $app->make(DocumentService::class),
                $app->make(AccountService::class),
                $app->make(BalanceService::class),
                $app->make(ReportService::class),
                $app->make(FiscalYearService::class),
                $app->make(OpeningService::class),
                $app->make(ClosingService::class),
                $app->make(PostingService::class),
                $app->make(ReversalService::class)
            );
        });

        $this->app->singleton(DocumentObserver::class, function ($app) {
            return new DocumentObserver(
                $app->make(BalanceService::class),
                $app->make(FiscalYearService::class)
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'accounting');

        Document::observe(DocumentObserver::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/accounting.php' => config_path('accounting.php'),
            ], 'accounting-config');

            $this->publishes([
                __DIR__ . '/../lang' => lang_path('vendor/accounting'),
            ], 'accounting-lang');
        }
    }
}
