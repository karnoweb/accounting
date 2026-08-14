<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InvalidFiscalYearException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use RuntimeException;

class FiscalYearLifecycleTest extends TestCase
{
    private function service(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    /**
     * @return array{fy: FiscalYear, debit: \Karnoweb\Accounting\Models\Account, credit: \Karnoweb\Accounting\Models\Account}
     */
    private function postedContext(float $amount = 100.0): array
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $fy = $this->service()->activate($fy);
        $chart = $this->createPostableChart();
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], $amount),
        ]));

        return [
            'fy' => $fy->fresh(),
            'debit' => $chart['detail'],
            'credit' => $chart['detail2'],
        ];
    }

    public function test_create_persists_draft_without_opening(): void
    {
        $fy = $this->service()->create([
            'title' => '  FY 2025  ',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertSame('FY 2025', $fy->title);
        $this->assertSame('2025-01-01', $fy->start_date->toDateString());
        $this->assertSame('2025-12-31', $fy->end_date->toDateString());
        $this->assertSame(FiscalYearStatus::DRAFT, $fy->status);
        $this->assertFalse($fy->is_current);
        $this->assertFalse($fy->opening_done);
        $this->assertNull($fy->opened_at);
        $this->assertNull($fy->closed_at);
    }

    public function test_create_rejects_inverted_date_range(): void
    {
        $this->expectException(InvalidFiscalYearException::class);

        $this->service()->create([
            'title' => 'Bad',
            'start_date' => '2025-12-31',
            'end_date' => '2025-01-01',
        ]);
    }

    public function test_create_allows_single_day_range(): void
    {
        $fy = $this->service()->create([
            'title' => 'One day',
            'start_date' => '2025-03-01',
            'end_date' => '2025-03-01',
        ]);

        $this->assertSame('2025-03-01', $fy->start_date->toDateString());
        $this->assertSame('2025-03-01', $fy->end_date->toDateString());
    }

    public function test_create_requires_title(): void
    {
        $this->expectException(InvalidFiscalYearException::class);

        $this->service()->create([
            'title' => '   ',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
    }

    public function test_create_rejects_lifecycle_fields(): void
    {
        $this->expectException(FiscalYearStateException::class);

        $this->service()->create([
            'title' => 'FY',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'active',
        ]);
    }

    public function test_create_rejects_exact_duplicate_range(): void
    {
        $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(FiscalYearOverlapException::class);

        $this->service()->create([
            'title' => 'Copy',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
    }

    public function test_create_rejects_overlapping_range(): void
    {
        $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(FiscalYearOverlapException::class);

        $this->service()->create([
            'title' => 'Overlap',
            'start_date' => '2025-06-01',
            'end_date' => '2026-05-31',
        ]);
    }

    public function test_create_allows_adjacent_non_overlapping_range(): void
    {
        $first = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $second = $this->service()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(FiscalYearStatus::DRAFT, $second->status);
    }

    public function test_draft_title_and_dates_can_be_edited(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $updated = $this->service()->update($fy, [
            'title' => 'FY 2025 revised',
            'start_date' => '2025-03-21',
            'end_date' => '2026-03-20',
        ]);

        $this->assertSame('FY 2025 revised', $updated->title);
        $this->assertSame('2025-03-21', $updated->start_date->toDateString());
        $this->assertSame('2026-03-20', $updated->end_date->toDateString());
        $this->assertSame(FiscalYearStatus::DRAFT, $updated->status);
    }

    public function test_draft_date_edit_rechecks_overlap(): void
    {
        $this->service()->create([
            'title' => 'FY 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]);
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(FiscalYearOverlapException::class);

        $this->service()->update($fy, [
            'start_date' => '2024-06-01',
            'end_date' => '2025-05-31',
        ]);
    }

    public function test_active_title_can_be_edited_without_touching_ledger(): void
    {
        $context = $this->postedContext(40);
        $itemCount = DocumentItem::query()->count();
        $documentCount = Document::query()->count();

        $updated = $this->service()->update($context['fy'], ['title' => 'FY 2025 renamed']);

        $this->assertSame('FY 2025 renamed', $updated->title);
        $this->assertSame('2025-01-01', $updated->start_date->toDateString());
        $this->assertSame(FiscalYearStatus::ACTIVE, $updated->status);
        $this->assertSame($itemCount, DocumentItem::query()->count());
        $this->assertSame($documentCount, Document::query()->count());
        $this->assertSame(40.0, (float) DocumentItem::query()->where('sign', 1)->sum('amount'));
    }

    public function test_active_date_range_cannot_be_changed(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));

        $this->expectException(FiscalYearStateException::class);

        $this->service()->update($fy, ['start_date' => '2025-01-02']);
    }

    public function test_closed_fiscal_year_cannot_be_edited(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $fy = $this->service()->close($fy);

        $this->expectException(FiscalYearStateException::class);

        $this->service()->update($fy, ['title' => 'nope']);
    }

    public function test_activate_draft_sets_current_and_opened_at(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $active = $this->service()->activate($fy);

        $this->assertSame(FiscalYearStatus::ACTIVE, $active->status);
        $this->assertTrue($active->is_current);
        $this->assertNotNull($active->opened_at);
        $this->assertFalse($active->opening_done);
        $this->assertSame($active->id, $this->service()->current()?->id);
    }

    public function test_activate_already_active_is_idempotent(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $openedAt = $fy->opened_at->toDateTimeString();

        $again = $this->service()->activate($fy);

        $this->assertSame(FiscalYearStatus::ACTIVE, $again->status);
        $this->assertTrue($again->is_current);
        $this->assertSame($openedAt, $again->opened_at->toDateTimeString());
        $this->assertFalse($again->opening_done);
    }

    public function test_cannot_activate_second_year_while_another_is_active(): void
    {
        $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $next = $this->service()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->expectException(FiscalYearStateException::class);

        $this->service()->activate($next);
    }

    public function test_cannot_activate_closed_fiscal_year(): void
    {
        $fy = $this->service()->close($this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ])));

        $this->expectException(FiscalYearStateException::class);

        $this->service()->activate($fy);
    }

    public function test_model_activate_delegates_to_service(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $active = $fy->activate();

        $this->assertTrue($active->isActive());
        $this->assertTrue($active->is_current);
    }

    public function test_draft_posting_is_rejected(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $chart = $this->createPostableChart();

        $this->expectException(FiscalYearStateException::class);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }

    public function test_active_posting_succeeds_and_closed_posting_is_rejected(): void
    {
        $context = $this->postedContext(25);
        $fy = $this->service()->close($context['fy']);

        $this->assertSame(DocumentStatus::POSTED, Document::query()->first()->status);
        $this->assertSame(25.0, (float) DocumentItem::query()->where('sign', 1)->sum('amount'));

        $this->expectException(ClosedFiscalYearException::class);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-07-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($context['debit'], $context['credit'], 10),
        ]);
    }

    public function test_document_post_delegates_and_rejects_out_of_range_date(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $chart = $this->createPostableChart();
        $draft = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 12),
        ]);

        $posted = $draft->post();
        $this->assertSame(DocumentStatus::POSTED, $posted->status);

        $this->expectException(RuntimeException::class);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2024-12-31',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }

    public function test_close_requires_active_year(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->expectException(FiscalYearStateException::class);

        $this->service()->close($fy);
    }

    public function test_close_sets_closed_at_and_clears_current_without_mutating_opening_done(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $documentsBefore = Document::query()->count();

        $closed = $this->service()->close($fy);

        $this->assertSame(FiscalYearStatus::CLOSED, $closed->status);
        $this->assertFalse($closed->is_current);
        $this->assertNotNull($closed->closed_at);
        $this->assertFalse($closed->opening_done);
        $this->assertNull($this->service()->current());
        $this->assertSame($documentsBefore, Document::query()->count());
        $this->assertSame(0, Document::query()->where('type', 'closing')->count());
        $this->assertSame(0, Document::query()->where('type', 'opening')->count());
    }

    public function test_closed_year_cannot_close_again(): void
    {
        $fy = $this->service()->close($this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ])));

        $this->expectException(FiscalYearStateException::class);

        $this->service()->close($fy);
    }

    public function test_unposted_documents_block_close(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $chart = $this->createPostableChart();
        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);

        $this->expectException(FiscalYearStateException::class);

        $this->service()->close($fy);
    }

    public function test_voided_documents_do_not_block_close(): void
    {
        $context = $this->postedContext(15);
        Document::query()->first()->void('correction');

        $closed = $this->service()->close($context['fy']);

        $this->assertTrue($closed->isClosed());
        $this->assertSame(DocumentStatus::VOIDED, Document::query()->first()->status);
    }

    public function test_current_never_returns_closed_or_draft(): void
    {
        $draft = $this->service()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $this->assertNull($this->service()->current());

        $active = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $this->assertSame($active->id, $this->service()->current()?->id);

        $this->service()->close($active);
        $this->assertNull($this->service()->current());
        $this->assertSame($draft->id, $this->service()->findByDate('2026-06-01')?->id);
        $this->assertTrue($this->service()->findByDate('2025-06-01')->isClosed());
    }

    public function test_find_by_date_returns_containing_year(): void
    {
        $fy = $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertSame($fy->id, $this->service()->findByDate('2025-01-01')?->id);
        $this->assertSame($fy->id, $this->service()->findByDate('2025-12-31')?->id);
        $this->assertNull($this->service()->findByDate('2024-12-31'));
    }

    public function test_find_by_date_rejects_ambiguous_overlap(): void
    {
        config(['accounting.fiscal_year.allow_overlap' => true]);

        $this->service()->create([
            'title' => 'A',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $this->service()->create([
            'title' => 'B',
            'start_date' => '2025-06-01',
            'end_date' => '2026-05-31',
        ]);

        $this->expectException(FiscalYearOverlapException::class);

        $this->service()->findByDate('2025-07-01');
    }

    public function test_close_then_activate_next_year_switches_current(): void
    {
        $fy2025 = $this->service()->close($this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ])));
        $fy2026 = $this->service()->activate($this->service()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]));

        $this->assertFalse($fy2025->fresh()->is_current);
        $this->assertTrue($fy2026->is_current);
        $this->assertSame($fy2026->id, $this->service()->current()?->id);
        $this->assertCount(1, FiscalYear::query()->where('is_current', true)->get());
        $this->assertCount(1, FiscalYear::query()->where('status', FiscalYearStatus::ACTIVE)->get());
    }

    public function test_closed_year_remains_readable_in_reports(): void
    {
        $context = $this->postedContext(80);
        $fy = $context['fy'];

        $tbBefore = Accounting::report()->trialBalanceDetailed($fy);
        $glBefore = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($context['debit'])->forFiscalYear($fy)
        )->forAccount($context['debit']->id);
        $statementBefore = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($context['debit'])->forFiscalYear($fy)
        );

        $closed = $this->service()->close($fy);
        $itemAmount = (float) DocumentItem::query()->where('sign', 1)->sum('amount');

        $tbAfter = Accounting::report()->trialBalanceDetailed($closed);
        $glAfter = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($context['debit'])->forFiscalYear($closed)
        )->forAccount($context['debit']->id);
        $statementAfter = Accounting::report()->accountStatement(
            LedgerQuery::make()->forAccount($context['debit'])->forFiscalYear($closed)
        );

        $this->assertSame(80.0, $itemAmount);
        $this->assertEqualsWithDelta($tbBefore->totals()['ending_debit'], $tbAfter->totals()['ending_debit'], 0.001);
        $this->assertEqualsWithDelta($tbAfter->totals()['ending_debit'], $tbAfter->totals()['ending_credit'], 0.001);
        $this->assertEqualsWithDelta($glBefore->closingBalance, $glAfter->closingBalance, 0.001);
        $this->assertEqualsWithDelta($statementBefore->closingBalance, $statementAfter->closingBalance, 0.001);
        $this->assertCount($glBefore->lines->count(), $glAfter->lines);
    }

    public function test_double_close_and_competing_activate_are_rejected_transactionally(): void
    {
        $fy = $this->service()->activate($this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $this->service()->close($fy);

        try {
            $this->service()->close($fy->id);
            $this->fail('Second close must fail');
        } catch (FiscalYearStateException $e) {
            $this->assertTrue($fy->fresh()->isClosed());
        }

        $next = $this->service()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $this->service()->activate($next);

        $other = $this->service()->create([
            'title' => 'FY 2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ]);

        try {
            $this->service()->activate($other);
            $this->fail('Competing activate must fail');
        } catch (FiscalYearStateException $e) {
            $this->assertSame($next->id, $this->service()->current()?->id);
            $this->assertSame(FiscalYearStatus::DRAFT, $other->fresh()->status);
        }
    }
}
