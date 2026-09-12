<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Support\Amount;

class MonetaryInvariantTest extends TestCase
{
    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    /**
     * @return array{asset: Account, contra: Account, income: Account, expense: Account, retained: Account}
     */
    private function chartWithTemporariesAndRetained(): array
    {
        $chart = $this->createPostableChart();
        $income = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Sales income',
            'type' => AccountType::INCOME,
        ]);
        $expense = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Rent expense',
            'type' => AccountType::EXPENSE,
        ]);

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
        $retained = Account::create([
            'parent_id' => $subsidiary->id,
            'code' => '310101',
            'title' => 'Retained earnings',
            'level' => $postingLevel,
            'type' => AccountType::EQUITY,
            'nature' => 'credit',
            'allow_direct_posting' => true,
            'is_active' => true,
        ]);
        config(['accounting.account.system_accounts.retained_earnings' => $retained->code]);

        return [
            'asset' => $chart['detail'],
            'contra' => $chart['detail2'],
            'income' => $income,
            'expense' => $expense,
            'retained' => $retained,
            'group' => $chart['group'],
        ];
    }

    public function test_classic_float_trap_document_is_balanced_and_posts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => '0.10', 'sign' => 1],
                ['account_id' => $chart['detail']->id, 'amount' => '0.20', 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => '0.30', 'sign' => -1],
            ],
        ]);

        $this->assertTrue($document->isBalanced());
        $this->assertTrue($this->documents()->isBalanced($document));
        $this->assertTrue(Amount::of($document->debit_total)->equals($document->credit_total));
        $this->assertSame('0.30', Amount::of($document->debit_total)->toStorage());
        $this->assertSame('0.30', Amount::of($document->credit_total)->toStorage());

        $posted = $this->documents()->post($document);

        $this->assertTrue($posted->isPosted());
        $this->assertSame('0.10', Amount::of($posted->items[0]->amount)->toStorage());
        $this->assertSame('0.20', Amount::of($posted->items[1]->amount)->toStorage());
        $this->assertSame('0.30', Amount::of($posted->items[2]->amount)->toStorage());
    }

    public function test_unbalanced_classic_float_values_are_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(UnbalancedDocumentException::class);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => '0.10', 'sign' => 1],
                ['account_id' => $chart['detail']->id, 'amount' => '0.20', 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => '0.31', 'sign' => -1],
            ],
        ]);
    }

    public function test_document_item_debit_credit_derivation(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.10'),
        ]);

        $debit = $document->items->firstWhere('sign', 1);
        $credit = $document->items->firstWhere('sign', -1);

        $this->assertSame('0.10', Amount::of($debit->amount)->toStorage());
        $this->assertSame('0.10', Amount::of($debit->debit)->toStorage());
        $this->assertTrue(Amount::of($debit->credit)->isZero());
        $this->assertSame('0.10', Amount::of($credit->amount)->toStorage());
        $this->assertTrue(Amount::of($credit->debit)->isZero());
        $this->assertSame('0.10', Amount::of($credit->credit)->toStorage());
        $this->assertTrue(Amount::of($debit->signed_amount)->equals('0.10'));
        $this->assertTrue(Amount::of($credit->signed_amount)->equals('-0.10'));
    }

    public function test_tiny_and_large_supported_amounts_post(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $tiny = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.01'),
        ]));

        $large = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '999999999999.99'),
        ]));

        $this->assertSame('0.01', Amount::of($tiny->items->first()->amount)->toStorage());
        $this->assertSame('999999999999.99', Amount::of($large->items->first()->amount)->toStorage());
        $this->assertTrue(Amount::of(Accounting::balance()->getBalance($chart['detail'], $fy))->equals('1000000000000.00'));
    }

    public function test_reversal_preserves_decimal_amounts_and_flips_sign(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $original = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => '0.10', 'sign' => 1],
                ['account_id' => $chart['detail']->id, 'amount' => '0.20', 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => '0.30', 'sign' => -1],
            ],
        ]));

        $reversal = Accounting::reversal()->reverse($original);

        $this->assertSame('reversal', $reversal->type);
        $this->assertTrue($reversal->isBalanced());
        $this->assertCount(3, $reversal->items);

        foreach ($original->items as $item) {
            $inverse = $reversal->items->firstWhere('order', $item->order);
            $this->assertNotNull($inverse);
            $this->assertTrue(Amount::of($inverse->amount)->equals($item->amount));
            $this->assertSame(-((int) $item->sign), (int) $inverse->sign);
        }

        $this->assertTrue(Amount::of(Accounting::balance()->getBalance($chart['detail'], $fy))->isZero());
        $this->assertTrue(Amount::of(Accounting::balance()->getBalance($chart['detail2'], $fy))->isZero());
    }

    public function test_opening_entries_accept_decimal_safe_amounts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(OpeningService::class)->post($fy, [
            ['account_id' => $chart['detail']->id, 'amount' => '0.10', 'sign' => 1],
            ['account_id' => $chart['detail']->id, 'amount' => '0.20', 'sign' => 1],
            ['account_id' => $chart['detail2']->id, 'amount' => '0.30', 'sign' => -1],
        ]);

        $this->assertSame(DocumentStatus::POSTED, $document->status);
        $this->assertTrue($document->isBalanced());
        $this->assertTrue(Amount::of($document->debit_total)->equals('0.30'));
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_closing_entries_sum_classic_float_trap_income(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->chartWithTemporariesAndRetained();

        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['asset'], $chart['income'], '0.10'),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'sale',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['asset'], $chart['income'], '0.20'),
        ]));

        $closings = app(ClosingService::class)->closeProfitAndLoss($fy);
        $closing = $closings[0];

        $incomeLine = $closing->items->firstWhere('account_id', $chart['income']->id);
        $reLine = $closing->items->firstWhere('account_id', $chart['retained']->id);

        $this->assertTrue($closing->isBalanced());
        $this->assertSame('0.30', Amount::of($incomeLine->amount)->toStorage());
        $this->assertSame(1, (int) $incomeLine->sign);
        $this->assertSame('0.30', Amount::of($reLine->amount)->toStorage());
        $this->assertSame(-1, (int) $reLine->sign);
        $this->assertTrue(app(ClosingService::class)->isProfitAndLossClosed($fy));
    }

    public function test_trial_balance_and_hierarchy_rollup_use_decimal_addition(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-01-05',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.10'),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-01-06',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.20'),
        ]));

        $report = Accounting::report()->trialBalanceDetailed($fy);
        $detail = $report->find($chart['detail']->id);
        $group = $report->find($chart['group']->id);
        $totals = $report->totals();

        $this->assertTrue(Amount::of($detail->periodDebit)->equals('0.30'));
        $this->assertTrue(Amount::of($group->periodDebit)->equals('0.30'));
        $this->assertTrue(Amount::of($totals['period_debit'])->equals('0.30'));
        $this->assertTrue(Amount::of($totals['period_credit'])->equals('0.30'));
        $this->assertTrue(Amount::of($totals['period_debit'])->equals($totals['period_credit']));
    }

    public function test_general_ledger_running_balance_is_decimal_safe(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-01-05',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.10'),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-01-10',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], '0.20'),
        ]));

        $ledger = Accounting::report()->generalLedger(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy)
        )->forAccount($chart['detail']->id);

        $this->assertTrue(Amount::of($ledger->openingBalance)->isZero());
        $this->assertTrue(Amount::of($ledger->lines[0]->runningBalance)->equals('0.10'));
        $this->assertTrue(Amount::of($ledger->lines[1]->runningBalance)->equals('0.30'));
        $this->assertTrue(Amount::of($ledger->closingBalance)->equals('0.30'));

        $paginated = Accounting::report()->accountStatementPaginated(
            LedgerQuery::make()->forAccount($chart['detail'])->forFiscalYear($fy),
            page: 1,
            perPage: 1
        );

        $this->assertTrue(Amount::of($paginated->lines[0]->runningBalance)->equals('0.10'));
        $this->assertTrue(Amount::of($paginated->closingBalance)->equals('0.30'));
    }

    public function test_branch_specific_balances_stay_isolated(): void
    {
        $fy = $this->createActiveFiscalYear();
        $branch1 = $this->createPostableChart('1');
        $branch1['detail']->update(['branch_id' => 1]);
        $branch1['detail2']->update(['branch_id' => 1]);

        $branch2 = $this->createPostableChart('2');
        $branch2['detail']->update(['branch_id' => 2]);
        $branch2['detail2']->update(['branch_id' => 2]);

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 1,
            'items' => $this->balancedItems($branch1['detail'], $branch1['detail2'], '0.10'),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 2,
            'items' => $this->balancedItems($branch2['detail'], $branch2['detail2'], '0.20'),
        ]));

        $this->assertTrue(Amount::of(Accounting::balance()->getBalance($branch1['detail'], $fy))->equals('0.10'));
        $this->assertTrue(Amount::of(Accounting::balance()->getBalance($branch2['detail'], $fy))->equals('0.20'));

        $b1 = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->branch(1)
        );
        $b2 = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->branch(2)
        );

        $this->assertTrue(Amount::of($b1->find($branch1['detail']->id)->periodDebit)->equals('0.10'));
        $this->assertTrue(Amount::of($b2->find($branch2['detail']->id)->periodDebit)->equals('0.20'));
        $this->assertTrue(Amount::of($b1->find($branch2['detail']->id)->periodDebit)->isZero());
    }

    public function test_builder_accepts_decimal_strings_without_float_round(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = Accounting::document()
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], '0.10')
            ->debit($chart['detail'], '0.20')
            ->credit($chart['detail2'], '0.30')
            ->post();

        $this->assertTrue($document->isBalanced());
        $this->assertSame(DocumentStatus::POSTED, $document->status);
        $this->assertTrue(Amount::of($document->debit_total)->equals('0.30'));
    }

    public function test_one_cent_difference_is_not_treated_as_balanced(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(UnbalancedDocumentException::class);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => '0.01', 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => '0.02', 'sign' => -1],
            ],
        ]);
    }

    public function test_carry_forward_preserves_decimal_permanent_balances(): void
    {
        $years = app(FiscalYearService::class);
        $source = $years->activate($years->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
        $chart = $this->createPostableChart();

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $source->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => '0.10', 'sign' => 1],
                ['account_id' => $chart['detail']->id, 'amount' => '0.20', 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => '0.30', 'sign' => -1],
            ],
        ]));

        $years->close($source);
        $target = $years->activate($years->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]));

        $documents = app(OpeningService::class)->carryForward($source, $target);
        $opening = app(OpeningService::class)->confirm($target);

        $this->assertNotEmpty($documents);
        $this->assertTrue($opening->isBalanced());
        $this->assertTrue(Amount::of($opening->items->firstWhere('account_id', $chart['detail']->id)->amount)->equals('0.30'));
        $this->assertTrue(Amount::of($opening->items->firstWhere('account_id', $chart['detail2']->id)->amount)->equals('0.30'));
        $this->assertSame(1, (int) $opening->items->firstWhere('account_id', $chart['detail']->id)->sign);
        $this->assertSame(-1, (int) $opening->items->firstWhere('account_id', $chart['detail2']->id)->sign);
    }
}
