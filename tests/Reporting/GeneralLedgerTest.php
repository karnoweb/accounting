<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    private function postDocument(FiscalYear $fy, string $date, Account $debitAccount, Account $creditAccount, float $amount, ?int $branchId = null): \Karnoweb\Accounting\Models\Document
    {
        return app(DocumentService::class)->post(app(DocumentService::class)->create([
            'type' => 'sale',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'reference' => 'REF-' . $amount,
            'description' => 'desc-' . $amount,
            'items' => $this->balancedItems($debitAccount, $creditAccount, $amount),
        ]));
    }

    // 1. opening balance
    public function test_opening_balance_reflects_prior_activity(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-01', $chart['detail'], $chart['detail2'], 100);

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->from('2025-06-01')->to('2025-12-31')
        )->forAccount($chart['detail']->id);

        $this->assertEqualsWithDelta(100.0, $ledger->openingBalance, 0.001);
        $this->assertCount(0, $ledger->lines);
    }

    // 2. multiple transactions
    public function test_multiple_transactions_all_appear(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail2'], $chart['detail'], 4);
        $this->postDocument($fy, '2025-01-15', $chart['detail'], $chart['detail2'], 6);

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        )->forAccount($chart['detail']->id);

        $this->assertCount(3, $ledger->lines);
    }

    // 3. running balance
    public function test_running_balance_accumulates_in_order(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 10);
        $this->postDocument($fy, '2025-01-10', $chart['detail2'], $chart['detail'], 4);
        $this->postDocument($fy, '2025-01-15', $chart['detail'], $chart['detail2'], 6);

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        )->forAccount($chart['detail']->id);

        $running = $ledger->lines->pluck('runningBalance')->all();
        $this->assertEquals([10.0, 6.0, 12.0], $running);
    }

    // 4. closing balance
    public function test_closing_balance_equals_opening_plus_period(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-01', $chart['detail'], $chart['detail2'], 50);
        $this->postDocument($fy, '2025-06-01', $chart['detail'], $chart['detail2'], 20);

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->from('2025-02-01')->to('2025-12-31')
        )->forAccount($chart['detail']->id);

        $this->assertEqualsWithDelta(50.0, $ledger->openingBalance, 0.001);
        $this->assertEqualsWithDelta(70.0, $ledger->closingBalance, 0.001);
    }

    // 5. deterministic same-date ordering
    public function test_deterministic_same_date_ordering(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 1);
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 2);
        $this->postDocument($fy, '2025-05-01', $chart['detail'], $chart['detail2'], 3);

        $first = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy))
            ->forAccount($chart['detail']->id)->lines->pluck('documentNumber')->all();
        $second = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy))
            ->forAccount($chart['detail']->id)->lines->pluck('documentNumber')->all();

        $this->assertSame(['1', '2', '3'], $first);
        $this->assertSame($first, $second);
    }

    // 6. FY filter
    public function test_fiscal_year_filter_scopes_lines(): void
    {
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $fy2026 = FiscalYear::create([
            'title' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => \Karnoweb\Accounting\Enums\FiscalYearStatus::ACTIVE, 'is_current' => false,
        ]);
        $chart = $this->createPostableChart();
        $this->postDocument($fy2025, '2025-06-01', $chart['detail'], $chart['detail2'], 5);
        $this->postDocument($fy2026, '2026-06-01', $chart['detail'], $chart['detail2'], 9);

        $ledger = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy2025))
            ->forAccount($chart['detail']->id);

        $this->assertCount(1, $ledger->lines);
        $this->assertEqualsWithDelta(5.0, $ledger->lines->first()->debit, 0.001);
    }

    // 7. branch filter
    public function test_branch_filter_scopes_lines(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 8, 1);
        $this->postDocument($fy, '2025-01-06', $chart['detail'], $chart['detail2'], 12, 2);

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)->branch(1)
        )->forAccount($chart['detail']->id);

        $this->assertCount(1, $ledger->lines);
        $this->assertEqualsWithDelta(8.0, $ledger->lines->first()->debit, 0.001);
    }

    // 8. voided excluded
    public function test_voided_document_excluded_from_lines(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 25);
        $document->void('mistake');

        $ledger = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy))
            ->forAccount($chart['detail']->id);

        $this->assertCount(0, $ledger->lines);
        $this->assertEqualsWithDelta(0.0, $ledger->closingBalance, 0.001);
    }

    // 9. posted-only (draft documents never contribute)
    public function test_draft_documents_never_appear(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-01-05',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 999),
        ]);

        $ledger = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy))
            ->forAccount($chart['detail']->id);

        $this->assertCount(0, $ledger->lines);
    }

    // 10. document metadata
    public function test_line_exposes_document_metadata(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = $this->postDocument($fy, '2025-01-05', $chart['detail'], $chart['detail2'], 33);

        $line = Accounting::report()->generalLedger(LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy))
            ->forAccount($chart['detail']->id)->lines->first();

        $this->assertSame($document->id, $line->documentId);
        $this->assertSame((string) $document->number, $line->documentNumber);
        $this->assertSame('sale', $line->documentType);
        $this->assertSame('desc-33', $line->documentDescription);
        $this->assertSame('REF-33', $line->reference);
        $this->assertSame($fy->id, $line->fiscalYearId);
        $this->assertEqualsWithDelta(33.0, $line->debit, 0.001);
        $this->assertEqualsWithDelta(0.0, $line->credit, 0.001);
        $this->assertEqualsWithDelta(33.0, $line->signedAmount(), 0.001);
    }
}
