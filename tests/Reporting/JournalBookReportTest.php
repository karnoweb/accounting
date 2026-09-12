<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class JournalBookReportTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_chronological_order_and_lines_stay_together(): void
    {
        $world = $this->turnoverWorld();
        $sameDayA = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 10, '2026-01-15', branchId: 1);
        $sameDayB = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 11, '2026-01-15', branchId: 1);

        $result = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(50));

        $dates = array_map(fn ($entry) => $entry->documentDate, $result->data);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $sameDay = array_values(array_filter($result->data, fn ($entry) => $entry->documentDate === '2026-01-15'));
        $this->assertSame($sameDayA->id, $sameDay[0]->documentId);
        $this->assertSame($sameDayB->id, $sameDay[1]->documentId);
        $this->assertGreaterThanOrEqual(2, $sameDay[0]->lineCount);
        $this->assertCount($sameDay[0]->lineCount, $sameDay[0]->lines);
    }

    public function test_cursor_pages_have_no_duplicates_or_skips(): void
    {
        $world = $this->turnoverWorld();
        for ($i = 0; $i < 49; $i++) {
            $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 1, '2026-01-28', branchId: 1);
        }

        $page1 = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(50));
        $this->assertTrue($page1->pagination->hasMore);
        $this->assertCount(50, $page1->data);

        $page2 = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(50, $page1->pagination->nextCursor));
        $this->assertCount(1, $page2->data);

        $ids1 = array_map(fn ($entry) => $entry->documentId, $page1->data);
        $ids2 = array_map(fn ($entry) => $entry->documentId, $page2->data);
        $this->assertSame([], array_intersect($ids1, $ids2));
        $this->assertTrue(Amount::of($page1->reportTotals['total_debit'])->equals($page2->reportTotals['total_debit']));
        $this->assertFalse(Amount::of($page1->pageTotals['total_debit'])->equals($page1->reportTotals['total_debit']));
        $this->assertTrue(Amount::of($page1->reportTotals['total_debit'])->equals($page1->reportTotals['total_credit']));
    }

    public function test_branch_matrix_and_breakdown(): void
    {
        $this->turnoverWorld();
        $base = ['from_date' => '2026-01-01', 'to_date' => '2026-01-31'];
        $a = $this->report()->journalBook($base + ['branch_id' => 1], $this->cursorPage());
        $b = $this->report()->journalBook($base + ['branch_id' => 2], $this->cursorPage());
        $all = $this->report()->journalBook($base + [
            'all_branches' => true,
            'include_branch_breakdown' => true,
        ], $this->cursorPage());

        $sum = Amount::of($a->reportTotals['total_debit'])->add($b->reportTotals['total_debit']);
        $this->assertTrue($sum->equals($all->reportTotals['total_debit']));
        $this->assertNotEmpty($all->branches);
    }

    public function test_filters_type_number_account_cost_center(): void
    {
        $world = $this->turnoverWorld();
        $sale = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 25, '2026-01-18', branchId: 1, type: 'sale');

        $byType = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'document_type' => 'sale',
        ], $this->cursorPage());
        $this->assertCount(1, $byType->data);
        $this->assertSame($sale->id, $byType->data[0]->documentId);

        $byNumber = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'document_number_from' => $sale->number,
            'document_number_to' => $sale->number,
        ], $this->cursorPage());
        $this->assertCount(1, $byNumber->data);

        $byAccount = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'account_id' => $world['expense']->id,
        ], $this->cursorPage());
        $this->assertCount(1, $byAccount->data);

        $byCenter = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'cost_center_id' => $world['center']->id,
        ], $this->cursorPage());
        $this->assertCount(2, $byCenter->data);
    }

    public function test_draft_void_reversal_and_audit_mode(): void
    {
        $world = $this->turnoverWorld();
        $this->createDraft($world['fy2026'], $world['cash'], $world['revenue'], 9, '2026-01-19', 1);
        $voided = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 12, '2026-01-19', branchId: 1);
        $voided->void('x');
        $original = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 15, '2026-01-19', branchId: 1);
        $original->reverse('x');

        $financial = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'mode' => 'financial',
        ], $this->cursorPage());
        $statuses = array_map(fn ($entry) => $entry->status, $financial->data);
        $this->assertNotContains('draft', $statuses);
        $this->assertNotContains('voided', $statuses);
        $this->assertContains('posted', $statuses);

        $audit = $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
            'mode' => 'audit',
        ], $this->cursorPage());
        $auditStatuses = array_map(fn ($entry) => $entry->status, $audit->data);
        $this->assertContains('voided', $auditStatuses);
        $this->assertContains('draft', $auditStatuses);
        $this->assertTrue(Amount::of($financial->reportTotals['total_debit'])->equals($financial->reportTotals['total_credit']));
    }

    public function test_no_n_plus_one_when_hydrating_lines(): void
    {
        $world = $this->turnoverWorld();
        for ($i = 0; $i < 8; $i++) {
            $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 2, '2026-01-27', branchId: 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->report()->journalBook([
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'branch_id' => 1,
        ], $this->cursorPage(20));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $count);
    }
}
