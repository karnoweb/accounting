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
use Karnoweb\Accounting\Services\ReportService;

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
        $this->app->singleton(ReportService::class);
        $this->app->singleton(FiscalYearService::class);

        $this->app->singleton(DocumentService::class, function ($app) {
            return new DocumentService(
                $app->make(BalanceService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton(DocumentBuilder::class, function ($app) {
            return new DocumentBuilder($app->make(DocumentService::class));
        });

        $this->app->singleton(ReportService::class, function ($app) {
            return new ReportService(
                $app->make(BalanceService::class),
                $app->make(AccountService::class)
            );
        });

        $this->app->singleton('accounting', function ($app) {
            return new AccountingManager(
                $app->make(DocumentBuilder::class),
                $app->make(AccountService::class),
                $app->make(BalanceService::class),
                $app->make(ReportService::class),
                $app->make(FiscalYearService::class)
            );
        });

        $this->app->singleton(DocumentObserver::class, function ($app) {
            return new DocumentObserver($app->make(BalanceService::class));
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

            $this->publishes([
                __DIR__ . '/../database/seeders' => database_path('seeders'),
            ], 'accounting-seeders');
        }
    }
}
