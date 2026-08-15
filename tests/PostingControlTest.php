<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Exception;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Events\DocumentPosted;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\PostingService;
use RuntimeException;

class PostingControlTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function posting(): PostingService
    {
        return app(PostingService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function opening(): OpeningService
    {
        return app(OpeningService::class);
    }

    private function closing(): ClosingService
    {
        return app(ClosingService::class);
    }

    private function activateYear(
        string $title = 'FY 2025',
        string $start = '2025-01-01',
        string $end = '2025-12-31'
    ): FiscalYear {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
    }

    public function test_facade_resolves_canonical_posting_service(): void
    {
        $this->assertInstanceOf(PostingService::class, Accounting::posting());
        $this->assertSame($this->posting(), Accounting::posting());

        $injected = (new \ReflectionProperty(DocumentService::class, 'postingService'))
            ->getValue(app(DocumentService::class));
        $this->assertSame($this->posting(), $injected);
    }

    public function test_active_fy_start_end_and_middle_dates_are_allowed(): void
    {
        $fy = $this->activateYear();

        $this->posting()->assertAllowed('2025-01-01', $fy);
        $this->posting()->assertAllowed('2025-06-15', $fy);
        $this->posting()->assertAllowed('2025-12-31', $fy);
        $this->posting()->assertAllowed('2025-12-31', $fy->id, 'sale', 3);

        $this->assertSame(0, Document::query()->count());
    }

    public function test_date_before_and_after_fy_is_rejected(): void
    {
        $fy = $this->activateYear();

        try {
            $this->posting()->assertAllowed('2024-12-31', $fy);
            $this->fail('Date before FY must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_out_of_fiscal_year'), $e->getMessage());
        }

        try {
            $this->posting()->assertAllowed('2026-01-01', $fy);
            $this->fail('Date after FY must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_out_of_fiscal_year'), $e->getMessage());
        }
    }

    public function test_draft_and_closed_fy_are_rejected(): void
    {
        $draft = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        try {
            $this->posting()->assertAllowed('2025-06-01', $draft);
            $this->fail('Draft FY must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.fiscal_year_not_active'), $e->getMessage());
        }

        $closed = $this->years()->close($this->activateYear('FY 2026', '2026-01-01', '2026-12-31'));

        try {
            $this->posting()->assertAllowed('2026-06-01', $closed);
            $this->fail('Closed FY must be rejected');
        } catch (ClosedFiscalYearException $e) {
            $this->assertSame(__('accounting::accounting.messages.fiscal_year_closed'), $e->getMessage());
        }
    }

    public function test_no_matching_fy_is_deterministic(): void
    {
        try {
            $this->posting()->assertAllowed('2010-01-01');
            $this->fail('Missing FY must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertNull($e->fiscalYear);
            $this->assertSame(__('accounting::accounting.messages.no_fiscal_year_for_date'), $e->getMessage());
        }
    }

    public function test_ambiguous_overlapping_fy_is_deterministic(): void
    {
        config(['accounting.fiscal_year.allow_overlap' => true]);
        $this->years()->create([
            'title' => 'FY A',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $this->years()->create([
            'title' => 'FY B',
            'start_date' => '2025-06-01',
            'end_date' => '2026-05-31',
        ]);

        try {
            $this->posting()->assertAllowed('2025-06-15');
            $this->fail('Ambiguous date must be rejected');
        } catch (FiscalYearOverlapException $e) {
            $this->assertSame(__('accounting::accounting.messages.fiscal_year_ambiguous'), $e->getMessage());
        }
    }

    public function test_date_in_another_fy_is_rejected_when_year_is_explicit(): void
    {
        $fy2025 = $this->activateYear();
        $this->years()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        try {
            $this->posting()->assertAllowed('2026-06-01', $fy2025);
            $this->fail('Date belonging to another FY must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_out_of_fiscal_year'), $e->getMessage());
        }

        try {
            $this->posting()->assertAllowed('2026-06-01');
            $this->fail('Date inside a draft FY must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.fiscal_year_not_active'), $e->getMessage());
        }
    }

    public function test_empty_and_malformed_dates_are_rejected(): void
    {
        $fy = $this->activateYear();

        try {
            $this->posting()->assertAllowed('', $fy);
            $this->fail('Empty date must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_required'), $e->getMessage());
        }

        try {
            $this->posting()->assertAllowed('not-a-date', $fy);
            $this->fail('Malformed date must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_required'), $e->getMessage());
        }
    }

    public function test_historical_and_future_dates_inside_active_fy_are_allowed(): void
    {
        $fy = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $chart = $this->createPostableChart();

        $this->posting()->assertAllowed('2026-01-02', $fy);
        $this->posting()->assertAllowed('2026-12-30', $fy);

        $historical = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2026-01-02',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 4),
        ]);
        $future = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2026-12-30',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 5),
        ]);

        $this->assertSame(DocumentStatus::DRAFT, $historical->status);
        $this->assertSame(DocumentStatus::DRAFT, $future->status);
    }

    public function test_document_service_uses_canonical_gate_and_does_not_persist_on_failure(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        try {
            $this->documents()->create([
                'type' => 'sale',
                'date' => '2024-12-31',
                'fiscal_year_id' => $fy->id,
                'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
            ]);
            $this->fail('Create outside FY must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(__('accounting::accounting.validation.date_out_of_fiscal_year'), $e->getMessage());
        }

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, Document::withTrashed()->count());
    }

    public function test_document_post_delegates_and_failed_post_leaves_draft(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $document = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 12),
        ]);

        $fy->update(['status' => FiscalYearStatus::CLOSED]);

        try {
            $document->post();
            $this->fail('Post into a closed FY must fail');
        } catch (ClosedFiscalYearException $e) {
            $this->assertSame(__('accounting::accounting.messages.fiscal_year_closed'), $e->getMessage());
        }

        $document = $document->fresh();
        $this->assertSame(DocumentStatus::DRAFT, $document->status);
        $this->assertNull($document->posted_at);
        $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($chart['detail'], $fy->fresh()), 0.001);
    }

    public function test_successful_post_updates_balance_and_emits_event(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        Event::fake([DocumentPosted::class]);

        $document = $this->documents()->create([
            'type' => 'sale',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]);
        $posted = $document->post();

        $this->assertSame(DocumentStatus::POSTED, $posted->status);
        $this->assertEqualsWithDelta(40.0, Accounting::balance()->getBalance($chart['detail'], $fy), 0.001);
        Event::assertDispatched(DocumentPosted::class, function (DocumentPosted $event) use ($posted) {
            return $event->document->id === $posted->id;
        });
    }

    public function test_erp_builder_path_asks_the_same_gate(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        $document = Accounting::document()
            ->type('sale')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 8)
            ->credit($chart['detail2'], 8)
            ->save();

        $this->assertSame(DocumentStatus::DRAFT, $document->status);
        $this->assertSame('sale', $document->type);
        $this->assertSame($fy->id, $document->fiscal_year_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('accounting::accounting.validation.date_out_of_fiscal_year'));

        Accounting::document()
            ->type('sale')
            ->date('2024-12-31')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 8)
            ->credit($chart['detail2'], 8)
            ->save();
    }

    public function test_idempotency_is_unchanged(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'sale-1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 3),
        ]);

        $this->expectException(DuplicateIdempotencyKeyException::class);
        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'sale-1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 3),
        ]);
    }

    public function test_opening_uses_start_date_and_cannot_bypass_the_gate(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $income = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Sales income',
            'type' => AccountType::INCOME,
        ]);

        $this->posting()->assertAllowed($fy->start_date->toDateString(), $fy, 'opening', null);

        try {
            $this->opening()->post($fy, $this->balancedItems($chart['detail'], $income, 2));
            $this->fail('Opening must keep permanent-account rules');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.opening_permanent_accounts_only'), $e->getMessage());
            $this->assertSame(0, Document::query()->where('type', 'opening')->count());
        }

        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 15));

        $this->assertSame('opening', $document->type);
        $this->assertSame('2025-01-01', $document->date->toDateString());
        $this->assertTrue($document->isPosted());

        $closed = $this->years()->close($fy);
        try {
            $this->opening()->post($closed, $this->balancedItems($chart['detail'], $chart['detail2'], 1));
            $this->fail('Opening must not post to a closed FY');
        } catch (ClosedFiscalYearException) {
            $this->assertSame(1, Document::query()->where('fiscal_year_id', $closed->id)->where('type', 'opening')->count());
            $this->assertSame(1, Document::query()->where('type', 'opening')->count());
        }
    }

    public function test_closing_uses_end_date_and_stays_off_fiscal_year_close(): void
    {
        $fy = $this->activateYear();
        $this->posting()->assertAllowed($fy->end_date->toDateString(), $fy, 'closing', null);
        $this->assertTrue($this->closing()->isProfitAndLossClosed($fy));

        $closed = $this->years()->close($fy);
        $this->assertTrue($closed->isClosed());
        $this->assertSame(0, Document::query()->where('type', 'closing')->count());

        $this->expectException(ClosedFiscalYearException::class);
        $this->closing()->closeProfitAndLoss($closed);
    }

    public function test_void_semantics_are_unchanged(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        $opening = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 20));
        $openingKey = $opening->idempotency_key;
        $opening->void('e7-opening');
        $this->assertSame(DocumentStatus::VOIDED, $opening->fresh()->status);
        $this->assertNull($opening->fresh()->idempotency_key);
        $this->assertFalse(Document::withTrashed()->where('idempotency_key', $openingKey)->exists());

        $operational = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'op-e7',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 6),
        ]));
        $operational->void('e7-ops');
        $this->assertSame('op-e7', $operational->fresh()->idempotency_key);

        try {
            $operational->post();
            $this->fail('Voided documents must not post again');
        } catch (Exception $e) {
            $this->assertSame(__('accounting::accounting.messages.document_cannot_post'), $e->getMessage());
        }

        $this->years()->close($fy);
        $next = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->opening()->post($next, $this->balancedItems($chart['detail'], $chart['detail2'], 1));

        $closing = $this->documents()->post($this->documents()->create([
            'type' => 'closing',
            'date' => '2026-12-31',
            'fiscal_year_id' => $next->id,
            'idempotency_key' => 'closing:'.$next->id.':branch:none',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]));
        $closingKey = $closing->idempotency_key;
        $closing->void('e7-closing');
        $this->assertNull($closing->fresh()->idempotency_key);
        $this->assertFalse(Document::withTrashed()->where('idempotency_key', $closingKey)->exists());
        $this->assertTrue($next->fresh()->opening_done);
    }

    public function test_null_branch_stays_distinct_and_default_is_not_injected(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 9,
        ]);
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        $this->posting()->assertAllowed('2025-06-01', $fy, 'sale', null);

        $explicitNull = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 2),
        ]);
        $explicitBranch = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 4,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 2),
        ]);

        $this->assertNull($explicitNull->branch_id);
        $this->assertSame(4, (int) $explicitBranch->branch_id);
        $this->assertNotSame(9, $explicitNull->branch_id);
    }

    public function test_fiscal_year_service_assert_accepts_posting_remains_intact(): void
    {
        $fy = $this->activateYear();
        $this->years()->assertAcceptsPosting($fy, '2025-06-01');

        $this->expectException(RuntimeException::class);
        $this->years()->assertAcceptsPosting($fy, '2024-12-31');
    }
}
