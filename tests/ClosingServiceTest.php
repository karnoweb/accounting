<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Exception;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Support\AccountHierarchy;

class ClosingServiceTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function opening(): OpeningService
    {
        return app(OpeningService::class);
    }

    private function closing(): ClosingService
    {
        return app(ClosingService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function activateYear(string $title, string $start, string $end): FiscalYear
    {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
    }

    /**
     * @return array{detail: Account, detail2: Account, income: Account, expense: Account, retained: Account}
     */
    private function chartWithRetainedEarnings(): array
    {
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $retained = $this->bindRetainedEarnings();

        return [
            'detail' => $chart['detail'],
            'detail2' => $chart['detail2'],
            'income' => $temps['income'],
            'expense' => $temps['expense'],
            'retained' => $retained,
        ];
    }

    private function bindRetainedEarnings(?Account $account = null): Account
    {
        if ($account === null) {
            $postingLevel = AccountHierarchy::postingLevel();
            $group = Account::create([
                'code' => '3',
                'title' => 'Equity',
                'level' => 0,
                'type' => AccountType::EQUITY,
                'nature' => 'credit',
                'allow_direct_posting' => false,
                'is_active' => true,
            ]);
            $general = Account::create([
                'parent_id' => $group->id,
                'code' => '31',
                'title' => 'Capital',
                'level' => 1,
                'type' => AccountType::EQUITY,
                'nature' => 'credit',
                'allow_direct_posting' => false,
                'is_active' => true,
            ]);
            $subsidiary = Account::create([
                'parent_id' => $general->id,
                'code' => '3101',
                'title' => 'Retained earnings',
                'level' => 2,
                'type' => AccountType::EQUITY,
                'nature' => 'credit',
                'allow_direct_posting' => false,
                'is_active' => true,
            ]);
            $account = Account::create([
                'parent_id' => $subsidiary->id,
                'code' => '310101',
                'title' => 'Retained earnings',
                'level' => $postingLevel,
                'type' => AccountType::EQUITY,
                'nature' => 'credit',
                'allow_direct_posting' => true,
                'is_active' => true,
            ]);
        }

        config(['accounting.account.system_accounts.retained_earnings' => $account->code]);

        return $account;
    }

    /**
     * @return array{income: Account, expense: Account}
     */
    private function temporaryAccounts(Account $parent): array
    {
        $income = app(AccountService::class)->create([
            'parent_id' => $parent->id,
            'title' => 'Sales income',
            'type' => AccountType::INCOME,
        ]);
        $expense = app(AccountService::class)->create([
            'parent_id' => $parent->id,
            'title' => 'Rent expense',
            'type' => AccountType::EXPENSE,
        ]);

        return compact('income', 'expense');
    }

    private function postInYear(
        FiscalYear $fy,
        Account $debit,
        Account $credit,
        float $amount,
        ?int $branchId = null,
        string $date = '2025-06-01',
        string $type = 'adjustment'
    ): Document {
        return $this->documents()->post($this->documents()->create([
            'type' => $type,
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $this->balancedItems($debit, $credit, $amount),
        ]));
    }

    private function assertClosingContract(Document $document, FiscalYear $fy, ?int $branchId): void
    {
        $this->assertSame('closing', $document->type);
        $this->assertTrue($document->isPosted());
        $this->assertSame($fy->id, $document->fiscal_year_id);
        $this->assertSame($fy->end_date->toDateString(), $document->date->toDateString());
        $this->assertSame($branchId, $document->branch_id);
        $this->assertSame('closing:'.$fy->id.':branch:'.($branchId ?? 'none'), $document->idempotency_key);
        $this->assertSame('close_pnl', $document->meta['operation']);
        $this->assertSame($fy->id, $document->meta['fiscal_year_id']);
        $this->assertEqualsWithDelta(0.0, (float) $document->items->sum(fn ($item) => $item->amount * $item->sign), 0.001);
    }

    public function test_income_only_credits_retained_earnings(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 80);

        $documents = $this->closing()->closeProfitAndLoss($fy);
        $this->assertCount(1, $documents);
        $this->assertClosingContract($documents[0], $fy, null);

        $income = $documents[0]->items->firstWhere('account_id', $chart['income']->id);
        $re = $documents[0]->items->firstWhere('account_id', $chart['retained']->id);
        $this->assertSame(1, $income->sign);
        $this->assertEqualsWithDelta(80.0, (float) $income->amount, 0.001);
        $this->assertSame(-1, $re->sign);
        $this->assertEqualsWithDelta(80.0, (float) $re->amount, 0.001);
        $this->assertTrue($this->closing()->isProfitAndLossClosed($fy));
        $this->assertTrue($fy->fresh()->isActive());
    }

    public function test_expense_only_debits_retained_earnings(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['expense'], $chart['detail2'], 55);

        $document = $this->closing()->closeProfitAndLoss($fy)[0];
        $expense = $document->items->firstWhere('account_id', $chart['expense']->id);
        $re = $document->items->firstWhere('account_id', $chart['retained']->id);
        $this->assertSame(-1, $expense->sign);
        $this->assertEqualsWithDelta(55.0, (float) $expense->amount, 0.001);
        $this->assertSame(1, $re->sign);
        $this->assertEqualsWithDelta(55.0, (float) $re->amount, 0.001);
    }

    public function test_profit_and_loss_net_to_retained_earnings(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 100);
        $this->postInYear($fy, $chart['expense'], $chart['detail2'], 40);

        $profit = $this->closing()->closeProfitAndLoss($fy)[0];
        $this->assertNull($profit->items->firstWhere('account_id', $chart['detail']->id));
        $this->assertEqualsWithDelta(60.0, (float) $profit->items->firstWhere('account_id', $chart['retained']->id)->amount, 0.001);
        $this->assertSame(-1, $profit->items->firstWhere('account_id', $chart['retained']->id)->sign);

        $this->years()->close($fy);
        $fy2 = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->postInYear($fy2, $chart['detail'], $chart['income'], 40, date: '2026-06-01');
        $this->postInYear($fy2, $chart['expense'], $chart['detail2'], 100, date: '2026-06-01');
        $loss = $this->closing()->closeProfitAndLoss($fy2)[0];
        $this->assertSame(1, $loss->items->firstWhere('account_id', $chart['retained']->id)->sign);
        $this->assertEqualsWithDelta(60.0, (float) $loss->items->firstWhere('account_id', $chart['retained']->id)->amount, 0.001);
    }

    public function test_immaterial_residual_omits_retained_earnings_line(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 50);
        $this->postInYear($fy, $chart['expense'], $chart['detail2'], 50);

        $document = $this->closing()->closeProfitAndLoss($fy)[0];
        $this->assertNull($document->items->firstWhere('account_id', $chart['retained']->id));
        $this->assertCount(2, $document->items);
        $this->assertTrue($this->closing()->isProfitAndLossClosed($fy));
    }

    public function test_empty_activity_completes_without_document(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $this->bindRetainedEarnings();

        $this->assertSame([], $this->closing()->closeProfitAndLoss($fy));
        $this->assertTrue($this->closing()->isProfitAndLossClosed($fy));
        $this->assertSame(0, Document::query()->where('type', 'closing')->count());
    }

    public function test_repeat_returns_same_documents(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 25);

        $first = $this->closing()->closeProfitAndLoss($fy);
        $second = $this->closing()->closeProfitAndLoss($fy);

        $this->assertSame([$first[0]->id], array_map(fn (Document $document) => $document->id, $second));
        $this->assertSame(1, Document::query()->where('type', 'closing')->count());
    }

    public function test_facade_resolves_closing_service(): void
    {
        $this->assertInstanceOf(ClosingService::class, Accounting::closing());
        $this->assertSame($this->closing(), Accounting::closing());
    }

    public function test_two_branches_close_independently(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 10, 1);
        $this->postInYear($fy, $chart['expense'], $chart['detail2'], 25, 2);

        $documents = collect($this->closing()->closeProfitAndLoss($fy))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $this->assertCount(2, $documents);
        $this->assertClosingContract($documents[1], $fy, 1);
        $this->assertClosingContract($documents[2], $fy, 2);
        $this->assertSame(-1, $documents[1]->items->firstWhere('account_id', $chart['retained']->id)->sign);
        $this->assertSame(1, $documents[2]->items->firstWhere('account_id', $chart['retained']->id)->sign);
    }

    public function test_null_branch_stays_distinct_from_default(): void
    {
        config(['accounting.branch.enabled' => true, 'accounting.branch.default_id' => 7]);
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 12, null);

        $document = $this->closing()->closeProfitAndLoss($fy)[0];
        $this->assertNull($document->branch_id);
        $this->assertSame('closing:'.$fy->id.':branch:none', $document->idempotency_key);
        $this->assertSame(0, Document::query()->where('type', 'closing')->where('branch_id', 7)->count());
    }

    public function test_branch_without_temporaries_gets_no_document(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['detail2'], 9, 1);
        $this->postInYear($fy, $chart['detail'], $chart['income'], 15, 2);

        $documents = $this->closing()->closeProfitAndLoss($fy);
        $this->assertCount(1, $documents);
        $this->assertSame(2, (int) $documents[0]->branch_id);
    }

    public function test_draft_and_closed_years_are_rejected(): void
    {
        $draft = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $this->bindRetainedEarnings();

        try {
            $this->closing()->closeProfitAndLoss($draft);
            $this->fail('Draft year must be rejected');
        } catch (FiscalYearStateException) {
            $this->assertSame(0, Document::query()->count());
        }

        $fy = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $closed = $this->years()->close($fy);
        $this->expectException(ClosedFiscalYearException::class);
        $this->closing()->closeProfitAndLoss($closed);
    }

    public function test_missing_and_invalid_retained_earnings_are_rejected(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($fy, $chart['detail'], $temps['income'], 10);

        config(['accounting.account.system_accounts.retained_earnings' => null]);
        try {
            $this->closing()->closeProfitAndLoss($fy);
            $this->fail('Missing RE must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.closing_retained_earnings_missing'), $e->getMessage());
        }

        config(['accounting.account.system_accounts.retained_earnings' => $temps['income']->code]);
        try {
            $this->closing()->closeProfitAndLoss($fy);
            $this->fail('Income RE must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.closing_retained_earnings_invalid'), $e->getMessage());
        }

        $this->assertSame(0, Document::query()->where('type', 'closing')->count());
    }

    public function test_non_postable_temporary_rolls_back_all_branches(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $otherIncome = app(AccountService::class)->create([
            'parent_id' => $chart['detail']->parent_id,
            'title' => 'Other income',
            'type' => AccountType::INCOME,
        ]);
        $this->postInYear($fy, $chart['detail'], $chart['income'], 10, 1);
        $this->postInYear($fy, $chart['detail'], $otherIncome, 20, 2);
        $otherIncome->update(['allow_direct_posting' => false]);

        try {
            $this->closing()->closeProfitAndLoss($fy);
            $this->fail('Non-postable temporary must abort');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.closing_non_postable_temporary'), $e->getMessage());
            $this->assertSame(0, Document::query()->where('type', 'closing')->count());
            $this->assertFalse($this->closing()->isProfitAndLossClosed($fy));
        }
    }

    public function test_opening_done_true_or_false_is_allowed(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->assertFalse($fy->opening_done);
        $this->postInYear($fy, $chart['detail'], $chart['income'], 8);
        $this->assertCount(1, $this->closing()->closeProfitAndLoss($fy));

        $this->years()->close($fy);
        $fy2 = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->years()->completeOpening($fy2);
        $this->postInYear($fy2, $chart['detail'], $chart['income'], 8, date: '2026-06-01');
        $this->assertTrue($fy2->fresh()->opening_done);
        $this->assertCount(1, $this->closing()->closeProfitAndLoss($fy2));
        $this->assertTrue($fy2->fresh()->opening_done);
        $this->assertTrue($fy2->fresh()->isActive());
    }

    public function test_void_releases_key_and_allows_retry(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 18);
        $first = $this->closing()->closeProfitAndLoss($fy)[0];
        $openingDone = $fy->fresh()->opening_done;

        $first->void('redo');
        $this->assertSame(DocumentStatus::VOIDED, $first->fresh()->status);
        $this->assertNull($first->fresh()->idempotency_key);
        $this->assertSame($openingDone, $fy->fresh()->opening_done);
        $this->assertFalse($this->closing()->isProfitAndLossClosed($fy));

        $second = $this->closing()->closeProfitAndLoss($fy)[0];
        $this->assertNotSame($first->id, $second->id);
        $this->assertClosingContract($second, $fy, null);
    }

    public function test_void_one_branch_leaves_the_other_posted(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 10, 1);
        $this->postInYear($fy, $chart['detail'], $chart['income'], 20, 2);
        $documents = collect($this->closing()->closeProfitAndLoss($fy))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $documents[1]->void('one');
        $this->assertSame(DocumentStatus::POSTED, $documents[2]->fresh()->status);
        $this->assertFalse($this->closing()->isProfitAndLossClosed($fy));
        $this->assertSame('closing:'.$fy->id.':branch:2', $documents[2]->fresh()->idempotency_key);
    }

    public function test_void_closing_in_closed_year_does_not_reopen(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->years()->completeOpening($fy);
        $this->postInYear($fy, $chart['detail'], $chart['income'], 11);
        $document = $this->closing()->closeProfitAndLoss($fy)[0];
        $closed = $this->years()->close($fy);

        $document->void('closed-year');

        $closed = $closed->fresh();
        $this->assertSame(FiscalYearStatus::CLOSED, $closed->status);
        $this->assertTrue($closed->opening_done);
        $this->assertNull($document->fresh()->idempotency_key);
    }

    public function test_operational_void_keeps_key_and_does_not_close_pnl(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 7);
        $operational = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'op-keep-close',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 3),
        ]));

        $operational->void('ops');
        $this->assertSame('op-keep-close', $operational->fresh()->idempotency_key);
        $this->assertFalse($this->closing()->isProfitAndLossClosed($fy));
        $this->assertSame(0, Document::query()->where('type', 'closing')->count());
    }

    public function test_carry_forward_still_requires_pnl_close_then_fy_close(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($source, $chart['detail'], $chart['income'], 70);

        $closedWithoutPnl = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        try {
            $this->opening()->carryForward($closedWithoutPnl, $target);
            $this->fail('Residual must still reject carry-forward');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.opening_pnl_residual'), $e->getMessage());
        }

        $this->years()->close($target);
        $source2 = $this->activateYear('FY 2027', '2027-01-01', '2027-12-31');
        $this->postInYear($source2, $chart['detail'], $chart['income'], 70, date: '2027-06-01');
        $this->closing()->closeProfitAndLoss($source2);

        $draftTarget = $this->years()->create([
            'title' => 'FY 2028',
            'start_date' => '2028-01-01',
            'end_date' => '2028-12-31',
        ]);
        try {
            $this->opening()->carryForward($source2, $draftTarget);
            $this->fail('Open source must still reject carry-forward');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.opening_source_not_closed'), $e->getMessage());
        }

        $closed = $this->years()->close($source2);
        $target3 = $this->years()->activate($draftTarget);
        $openings = $this->opening()->carryForward($closed, $target3);
        $this->assertCount(1, $openings);
        $this->assertNull($openings[0]->items->firstWhere('account_id', $chart['income']->id));
        $re = $openings[0]->items->firstWhere('account_id', $chart['retained']->id);
        $this->assertNotNull($re);
        $this->assertSame(-1, $re->sign);
        $this->assertEqualsWithDelta(70.0, (float) $re->amount, 0.001);
    }

    public function test_reporting_treats_closing_as_period_on_end_date(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 90);
        $this->closing()->closeProfitAndLoss($fy);

        $fullYear = Accounting::report()->trialBalanceDetailed($fy);
        $income = $fullYear->find($chart['income']->id);
        $re = $fullYear->find($chart['retained']->id);
        $this->assertEqualsWithDelta(0.0, $income->endingBalance(), 0.001);
        $this->assertEqualsWithDelta(0.0, $income->openingBalance(), 0.001);
        $this->assertEqualsWithDelta(-90.0, $re->endingBalance(), 0.001);
        $totals = $fullYear->totals();
        $this->assertEqualsWithDelta($totals['period_debit'], $totals['period_credit'], 0.001);
        $this->assertEqualsWithDelta($totals['ending_debit'], $totals['ending_credit'], 0.001);

        $beforeClose = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->from('2025-01-01')->to('2025-06-30')
        );
        $this->assertEqualsWithDelta(-90.0, $beforeClose->find($chart['income']->id)->endingBalance(), 0.001);
        $this->assertEqualsWithDelta(0.0, $beforeClose->find($chart['retained']->id)?->endingBalance() ?? 0.0, 0.001);

        $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($chart['income'], $fy), 0.001);
        $this->assertEqualsWithDelta(-90.0, Accounting::balance()->getBalance($chart['retained'], $fy), 0.001);
    }

    public function test_mismatched_existing_closing_is_rejected(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 30);
        $this->documents()->post($this->documents()->create([
            'type' => 'closing',
            'date' => '2025-12-31',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'idempotency_key' => 'closing:'.$fy->id.':branch:none',
            'items' => $this->balancedItems($chart['income'], $chart['retained'], 5),
        ]));

        try {
            $this->closing()->closeProfitAndLoss($fy);
            $this->fail('Mismatched closing must be rejected');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(__('accounting::accounting.messages.closing_inconsistent_state'), $e->getMessage());
            $this->assertSame(1, Document::query()->where('type', 'closing')->count());
        }
    }

    public function test_voided_closing_is_immutable(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->chartWithRetainedEarnings();
        $this->postInYear($fy, $chart['detail'], $chart['income'], 6);
        $document = $this->closing()->closeProfitAndLoss($fy)[0];
        $document->void('once');

        try {
            $document->fresh()->update(['description' => 'no']);
            $this->fail('Voided closing must reject update');
        } catch (DocumentNotEditableException) {
            $this->assertSame(DocumentStatus::VOIDED, $document->fresh()->status);
        }

        try {
            $document->fresh()->void('twice');
            $this->fail('Second void must be rejected');
        } catch (Exception $e) {
            $this->assertSame(DocumentStatus::VOIDED, $document->fresh()->status);
            $this->assertStringContainsString(
                __('accounting::accounting.messages.document_not_voidable'),
                $e->getMessage()
            );
        }
    }
}
