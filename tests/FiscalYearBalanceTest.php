<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\Cache;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentService;

class FiscalYearBalanceTest extends TestCase
{
    public function test_fy_balances_are_isolated_and_cache_safe(): void
    {
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $fy2026 = FiscalYear::create([
            'title' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::ACTIVE,
            'is_current' => false,
        ]);
        $chart = $this->createPostableChart();
        $balances = app(BalanceService::class);

        $doc2025 = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy2025->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 100),
        ]);
        app(DocumentService::class)->post($doc2025);

        // Warm FY2025 cache
        $this->assertEqualsWithDelta(100.0, $balances->getBalance($chart['detail'], $fy2025), 0.001);
        $this->assertTrue(Cache::has($balances->fiscalYearCacheKey($chart['detail'], $fy2025)));

        $doc2026 = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2026-03-01',
            'fiscal_year_id' => $fy2026->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]);
        app(DocumentService::class)->post($doc2026);

        $this->assertEqualsWithDelta(100.0, $balances->getBalance($chart['detail'], $fy2025), 0.001);
        $this->assertEqualsWithDelta(40.0, $balances->getBalance($chart['detail'], $fy2026), 0.001);
        $this->assertNotSame(
            $balances->fiscalYearCacheKey($chart['detail'], $fy2025),
            $balances->fiscalYearCacheKey($chart['detail'], $fy2026)
        );

        // Same account + same FY returns cached value
        Cache::put($balances->fiscalYearCacheKey($chart['detail'], $fy2025), 100.0, 3600);
        $this->assertEqualsWithDelta(100.0, $balances->getBalance($chart['detail'], $fy2025), 0.001);
    }

    public function test_voiding_invalidates_fy_balance(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $balances = app(BalanceService::class);

        $doc = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 75),
        ]);
        $posted = app(DocumentService::class)->post($doc);
        $this->assertEqualsWithDelta(75.0, $balances->getBalance($chart['detail'], $fy), 0.001);

        $posted->void('test');

        $this->assertEqualsWithDelta(0.0, $balances->getBalance($chart['detail'], $fy, true), 0.001);
        $this->assertEqualsWithDelta(0.0, $balances->getBalance($chart['detail'], $fy), 0.001);
    }

    public function test_lifetime_cache_ignored_when_fiscal_year_supplied(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $balances = app(BalanceService::class);

        $doc = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 55),
        ]);
        app(DocumentService::class)->post($doc);

        $chart['detail']->update([
            'cached_balance' => 9999,
            'balance_updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta(55.0, $balances->getBalance($chart['detail']->fresh(), $fy), 0.001);
    }
}
