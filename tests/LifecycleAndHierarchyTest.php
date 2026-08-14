<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Exception;

class LifecycleAndHierarchyTest extends TestCase
{
    public function test_valid_void_transition(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $doc = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);
        $posted = app(DocumentService::class)->post($doc);
        $voided = $posted->void('mistake');

        $this->assertSame(DocumentStatus::VOIDED, $voided->status);
    }

    public function test_voided_cannot_be_posted_again(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $doc = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);
        $posted = app(DocumentService::class)->post($doc);
        $voided = $posted->void('mistake');

        $this->expectException(Exception::class);
        $voided->post();
    }

    public function test_voided_cannot_be_edited(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $doc = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);
        $voided = app(DocumentService::class)->post($doc)->void('x');

        $this->expectException(DocumentNotEditableException::class);
        $voided->update(['description' => 'no']);
    }

    public function test_account_hierarchy_rejects_beyond_max_level(): void
    {
        $chart = $this->createPostableChart();

        $this->expectException(InvalidAccountHierarchyException::class);

        app(AccountService::class)->create([
            'parent_id' => $chart['detail']->id,
            'title' => 'Too deep',
            'type' => 'asset',
        ]);
    }

    public function test_posting_level_accounts_are_created_postable(): void
    {
        $group = app(AccountService::class)->create([
            'code' => '9',
            'title' => 'G',
            'type' => 'asset',
        ]);
        $l1 = app(AccountService::class)->create([
            'parent_id' => $group->id,
            'title' => 'L1',
            'type' => 'asset',
        ]);
        $l2 = app(AccountService::class)->create([
            'parent_id' => $l1->id,
            'title' => 'L2',
            'type' => 'asset',
        ]);
        $l3 = app(AccountService::class)->create([
            'parent_id' => $l2->id,
            'title' => 'L3',
            'type' => 'asset',
        ]);

        $this->assertFalse($group->fresh()->allow_direct_posting);
        $this->assertFalse($l1->fresh()->allow_direct_posting);
        $this->assertFalse($l2->fresh()->allow_direct_posting);
        $this->assertTrue($l3->fresh()->allow_direct_posting);
        $this->assertSame(AccountHierarchy::postingLevel(), $l3->level);
        $this->assertTrue($l3->isPostable());
    }

    public function test_fiscal_year_overlap_rejected(): void
    {
        $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31');

        $this->expectException(FiscalYearOverlapException::class);

        app(FiscalYearService::class)->create([
            'title' => 'Overlap',
            'start_date' => '2025-06-01',
            'end_date' => '2026-05-31',
        ]);
    }

    public function test_package_version_matches_composer(): void
    {
        $this->assertSame('13.3.0', Accounting::version());
    }
}
