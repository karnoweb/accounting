<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Karnoweb\Accounting\AccountingServiceProvider;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AccountingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('accounting.branch.enabled', false);
        $app['config']->set('accounting.branch.default_id', null);
        $app['config']->set('accounting.branch.model', null);
        $app['config']->set('accounting.user.model', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function createActiveFiscalYear(
        string $title = 'FY 2025',
        string $start = '2025-01-01',
        string $end = '2025-12-31',
        bool $current = true
    ): FiscalYear {
        return FiscalYear::create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
            'status' => FiscalYearStatus::ACTIVE,
            'is_current' => $current,
        ]);
    }

    /**
     * @return array{group: Account, general: Account, subsidiary: Account, detail: Account, detail2: Account}
     */
    protected function createPostableChart(string $prefix = '1'): array
    {
        $postingLevel = AccountHierarchy::postingLevel();

        $group = Account::create([
            'code' => $prefix,
            'title' => 'Group ' . $prefix,
            'level' => 0,
            'type' => AccountType::ASSET,
            'nature' => 'debit',
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);

        $general = Account::create([
            'parent_id' => $group->id,
            'code' => $prefix . '1',
            'title' => 'General',
            'level' => 1,
            'type' => AccountType::ASSET,
            'nature' => 'debit',
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);

        $subsidiary = Account::create([
            'parent_id' => $general->id,
            'code' => $prefix . '101',
            'title' => 'Subsidiary',
            'level' => 2,
            'type' => AccountType::ASSET,
            'nature' => 'debit',
            'allow_direct_posting' => false,
            'is_active' => true,
        ]);

        $detail = Account::create([
            'parent_id' => $subsidiary->id,
            'code' => $prefix . '10101',
            'title' => 'Detail A',
            'level' => $postingLevel,
            'type' => AccountType::ASSET,
            'nature' => 'debit',
            'allow_direct_posting' => true,
            'is_active' => true,
        ]);

        $detail2 = Account::create([
            'parent_id' => $subsidiary->id,
            'code' => $prefix . '10102',
            'title' => 'Detail B',
            'level' => $postingLevel,
            'type' => AccountType::LIABILITY,
            'nature' => 'credit',
            'allow_direct_posting' => true,
            'is_active' => true,
        ]);

        return compact('group', 'general', 'subsidiary', 'detail', 'detail2');
    }

    protected function balancedItems(Account $debit, Account $credit, float $amount = 100.0): array
    {
        return [
            ['account_id' => $debit->id, 'amount' => $amount, 'sign' => 1, 'description' => 'debit'],
            ['account_id' => $credit->id, 'amount' => $amount, 'sign' => -1, 'description' => 'credit'],
        ];
    }
}
