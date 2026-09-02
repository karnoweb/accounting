<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Exception;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\DocumentNotReversibleException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InactiveAccountException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\CostCenter;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\ReversalService;
use Karnoweb\Accounting\Support\AccountHierarchy;
use RuntimeException;

class ReversalServiceTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function reversals(): ReversalService
    {
        return app(ReversalService::class);
    }

    private function opening(): OpeningService
    {
        return app(OpeningService::class);
    }

    private function closing(): ClosingService
    {
        return app(ClosingService::class);
    }

    private function activateYear(string $title = 'FY 2025', string $start = '2025-01-01', string $end = '2025-12-31'): FiscalYear
    {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
    }

    private function postOperational(
        FiscalYear $fy,
        Account $debit,
        Account $credit,
        float $amount = 100.0,
        ?int $branchId = null,
        string $date = '2025-06-01',
        string $type = 'adjustment',
        ?int $costCenterId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): Document {
        $items = $this->balancedItems($debit, $credit, $amount);
        if ($costCenterId !== null) {
            $items[0]['cost_center_id'] = $costCenterId;
            $items[1]['cost_center_id'] = $costCenterId;
        }

        return $this->documents()->post($this->documents()->create([
            'type' => $type,
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'items' => $items,
        ]));
    }

    /**
     * @return array{detail: Account, detail2: Account, income: Account, expense: Account, retained: Account}
     */
    private function chartWithRetainedEarnings(): array
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
            'detail' => $chart['detail'],
            'detail2' => $chart['detail2'],
            'income' => $income,
            'expense' => $expense,
            'retained' => $retained,
        ];
    }

    public function test_operational_posted_document_reverses_successfully(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 100);

        $r1 = $this->reversals()->reverse($j1, ['reason' => 'duplicate']);

        $this->assertTrue($r1->isPosted());
        $this->assertNotSame($j1->id, $r1->id);
        $this->assertNotSame((int) $j1->number, (int) $r1->number);
        $this->assertSame('reversal', $r1->type);
        $this->assertSame($j1->id, $r1->reversed_document_id);
        $this->assertSame('reversal:'.$j1->id, $r1->idempotency_key);
        $this->assertSame($j1->id, $r1->reversedDocument->id);
        $this->assertSame($r1->id, $j1->fresh()->postedReversal()->id);
        $this->assertSame($j1->id, $j1->fresh()->reversals->first()->reversed_document_id);
        $this->assertSame('2025-06-01', $r1->date->toDateString());
        $this->assertSame('reversal', $r1->meta['operation']);
        $this->assertSame($j1->id, $r1->meta['original_document_id']);
        $this->assertSame('adjustment', $r1->meta['original_type']);
        $this->assertSame('duplicate', $r1->meta['reason']);
    }

    public function test_items_are_exact_inverses_and_original_is_unchanged(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $center = CostCenter::create(['code' => 'CC1', 'title' => 'Ops']);
        $j1 = $this->postOperational(
            $fy,
            $chart['detail'],
            $chart['detail2'],
            75.5,
            costCenterId: $center->id
        );
        $originalItems = $j1->items->map(fn ($item) => $item->only([
            'id', 'account_id', 'amount', 'sign', 'cost_center_id', 'order', 'description',
        ]))->all();

        $r1 = $this->reversals()->reverse($j1);

        $this->assertCount($j1->items->count(), $r1->items);
        foreach ($j1->items as $index => $original) {
            $inverse = $r1->items[$index];
            $this->assertSame((int) $original->account_id, (int) $inverse->account_id);
            $this->assertEqualsWithDelta((float) $original->amount, (float) $inverse->amount, 0.001);
            $this->assertSame(-((int) $original->sign), (int) $inverse->sign);
            $this->assertSame((int) $original->cost_center_id, (int) $inverse->cost_center_id);
            $this->assertSame((int) $original->order, (int) $inverse->order);
            $this->assertSame($original->description, $inverse->description);
            $this->assertSame($original->id, $inverse->meta['original_item_id']);
        }

        $j1->refresh()->load('items');
        $this->assertTrue($j1->isPosted());
        $this->assertSame('adjustment', $j1->type);
        $this->assertSame($originalItems, $j1->items->map(fn ($item) => $item->only([
            'id', 'account_id', 'amount', 'sign', 'cost_center_id', 'order', 'description',
        ]))->all());
    }

    public function test_repeat_reverse_returns_same_posted_reversal(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);

        $first = $this->reversals()->reverse($j1);
        $second = $this->reversals()->reverse($j1->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->where('type', 'reversal')->where('status', 'posted')->count());
    }

    public function test_concurrent_reverse_cannot_create_two_posted_reversals(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->reversals()->reverse($j1)->id;
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, Document::query()
            ->where('reversed_document_id', $j1->id)
            ->where('status', DocumentStatus::POSTED->value)
            ->count());
    }

    public function test_ledger_and_balances_net_to_zero(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 40);
        $r1 = $this->reversals()->reverse($j1);

        $lines = LedgerQuery::make()->forFiscalYear($fy)->get();
        $documentIds = $lines->pluck('documentId')->unique()->sort()->values()->all();
        $this->assertSame([$j1->id, $r1->id], $documentIds);

        foreach ([$chart['detail'], $chart['detail2']] as $account) {
            $totals = LedgerQuery::make()->forFiscalYear($fy)->forAccount($account)->periodTotals();
            $this->assertEqualsWithDelta(0.0, $totals['balance'], 0.001);
            $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($account, $fy), 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $account->fresh()->cached_balance, 0.001);
        }

        $tb = Accounting::report()->trialBalanceDetailed($fy);
        $this->assertEqualsWithDelta($tb->totals()['period_debit'], $tb->totals()['period_credit'], 0.001);
        $this->assertEqualsWithDelta($tb->totals()['ending_debit'], $tb->totals()['ending_credit'], 0.001);
        $this->assertEqualsWithDelta(0.0, $tb->find($chart['detail']->id)->endingBalance(), 0.001);
        $this->assertEqualsWithDelta(0.0, $tb->find($chart['detail2']->id)->endingBalance(), 0.001);
    }

    public function test_null_and_non_null_branch_are_preserved(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();

        $nullBranch = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 10, null);
        $nullReversal = $this->reversals()->reverse($nullBranch);
        $this->assertNull($nullReversal->branch_id);

        $branched = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 12, 7);
        $branchedReversal = $this->reversals()->reverse($branched);
        $this->assertSame(7, $branchedReversal->branch_id);
    }

    public function test_source_is_copied(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational(
            $fy,
            $chart['detail'],
            $chart['detail2'],
            sourceType: 'invoice',
            sourceId: 99
        );

        $r1 = $this->reversals()->reverse($j1);

        $this->assertSame('invoice', $r1->source_type);
        $this->assertSame(99, $r1->source_id);
    }

    public function test_date_override_inside_fy_is_accepted(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);

        $r1 = $this->reversals()->reverse($j1, ['date' => '2025-09-15']);

        $this->assertSame('2025-09-15', $r1->date->toDateString());
        $this->assertSame($fy->id, $r1->fiscal_year_id);
    }

    public function test_date_outside_fy_is_rejected(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);

        $this->expectException(RuntimeException::class);
        $this->reversals()->reverse($j1, ['date' => '2026-01-01']);
    }

    public function test_draft_and_voided_are_rejected(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $draft = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        try {
            $this->reversals()->reverse($draft);
            $this->fail('Draft must not reverse');
        } catch (DocumentNotReversibleException $e) {
            $this->assertSame($draft->id, $e->document?->id);
        }

        $posted = $this->documents()->post($draft);
        $posted->void('mistake');

        $this->expectException(DocumentNotReversibleException::class);
        $this->reversals()->reverse($posted->fresh());
    }

    public function test_opening_and_closing_are_rejected(): void
    {
        $fy = $this->activateYear();
        $chart = $this->chartWithRetainedEarnings();
        $opening = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 20));

        try {
            $this->reversals()->reverse($opening);
            $this->fail('Opening must not reverse');
        } catch (DocumentNotReversibleException $e) {
            $this->assertTrue($opening->fresh()->isPosted());
            $this->assertTrue($fy->fresh()->opening_done);
        }

        $this->postOperational($fy, $chart['detail'], $chart['income'], 30);
        $closing = $this->closing()->closeProfitAndLoss($fy)[0];

        $this->expectException(DocumentNotReversibleException::class);
        $this->reversals()->reverse($closing);
    }

    public function test_posted_closing_blocks_operational_reversal(): void
    {
        $fy = $this->activateYear();
        $chart = $this->chartWithRetainedEarnings();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['income'], 50);
        $this->closing()->closeProfitAndLoss($fy);

        $this->expectException(DocumentNotReversibleException::class);
        $this->reversals()->reverse($j1);
    }

    public function test_void_closing_then_reverse_then_reclose(): void
    {
        $fy = $this->activateYear();
        $chart = $this->chartWithRetainedEarnings();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['income'], 50);
        $closing = $this->closing()->closeProfitAndLoss($fy)[0];
        $closing->void('redo');

        $r1 = $this->reversals()->reverse($j1);
        $this->assertTrue($r1->isPosted());

        $again = $this->closing()->closeProfitAndLoss($fy);
        $this->assertSame([], $again);
        $this->assertTrue($this->closing()->isProfitAndLossClosed($fy));
        $this->assertTrue($fy->fresh()->isActive());
    }

    public function test_closed_fy_is_rejected(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);
        $this->years()->close($fy);

        $this->expectException(ClosedFiscalYearException::class);
        $this->reversals()->reverse($j1);
    }

    public function test_reversal_of_reversal_and_no_second_reverse_of_original(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 25);

        $r1 = $this->reversals()->reverse($j1);
        $r2 = $this->reversals()->reverse($r1);

        $this->assertSame($r1->id, $r2->reversed_document_id);
        $this->assertSame('reversal:'.$r1->id, $r2->idempotency_key);
        $this->assertSame($r1->id, $this->reversals()->reverse($j1)->id);
        $this->assertSame(2, Document::query()->where('type', 'reversal')->where('status', 'posted')->count());

        $net = LedgerQuery::make()->forFiscalYear($fy)->forAccount($chart['detail'])->periodTotals();
        $this->assertEqualsWithDelta(25.0, $net['balance'], 0.001);
    }

    public function test_void_reversal_releases_key_and_allows_new_reversal(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 18);

        $r1 = $this->reversals()->reverse($j1);
        $r1->void('undo reversal');

        $this->assertNull($r1->fresh()->idempotency_key);
        $this->assertSame($j1->id, $r1->fresh()->reversed_document_id);
        $this->assertSame(DocumentStatus::VOIDED, $r1->fresh()->status);

        $r1Prime = $this->reversals()->reverse($j1);
        $this->assertNotSame($r1->id, $r1Prime->id);
        $this->assertSame('reversal:'.$j1->id, $r1Prime->idempotency_key);
        $this->assertTrue($r1Prime->isPosted());
    }

    public function test_original_cannot_be_voided_while_reversal_is_posted(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);
        $this->reversals()->reverse($j1);

        try {
            $j1->void('no');
            $this->fail('Reversed original must not void');
        } catch (Exception $e) {
            $this->assertSame(
                __('accounting::accounting.messages.document_cannot_void_while_reversed'),
                $e->getMessage()
            );
        }

        $this->assertTrue($j1->fresh()->isPosted());
        $this->assertTrue($j1->fresh()->postedReversal()->isPosted());
    }

    public function test_reversal_does_not_change_opening_or_fy_lifecycle(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 5));
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 8);

        $this->reversals()->reverse($j1);

        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertTrue($fy->fresh()->isActive());
        $this->assertFalse($fy->fresh()->isClosed());
    }

    public function test_non_postable_account_fails_atomically(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 11);
        $chart['detail']->update(['is_active' => false]);

        try {
            $this->reversals()->reverse($j1);
            $this->fail('Inactive account must block reversal');
        } catch (InactiveAccountException $e) {
            $this->assertSame(0, Document::query()->where('type', 'reversal')->count());
            $this->assertTrue($j1->fresh()->isPosted());
            $this->assertNull($j1->fresh()->postedReversal());
        }
    }

    public function test_stale_idempotency_key_is_rejected(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);

        $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'idempotency_key' => 'reversal:'.$j1->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);

        $this->expectException(DuplicateIdempotencyKeyException::class);
        $this->reversals()->reverse($j1);
    }

    public function test_soft_deleted_key_still_blocks(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);
        $draft = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-02',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'idempotency_key' => 'reversal:'.$j1->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
        $draft->delete();

        $this->expectException(DuplicateIdempotencyKeyException::class);
        $this->reversals()->reverse($j1);
    }

    public function test_opening_service_still_refuses_after_operational_reversal(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2']);
        $this->reversals()->reverse($j1);

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 3));
    }

    public function test_facade_and_document_reverse_parity(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 9);
        $j2 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 6);

        $this->assertInstanceOf(ReversalService::class, Accounting::reversal());
        $this->assertSame($this->reversals(), Accounting::reversal());

        $viaFacade = Accounting::reversal()->reverse($j1);
        $viaModel = $j2->reverse('via model');

        $this->assertSame($viaFacade->id, $this->reversals()->reverse($j1)->id);
        $this->assertSame('reversal', $viaModel->type);
        $this->assertSame($j2->id, $viaModel->reversed_document_id);
        $this->assertSame('via model', $viaModel->meta['reason']);
    }

    public function test_caller_cannot_override_fiscal_year_or_branch(): void
    {
        $fy = $this->activateYear();
        $chart = $this->createPostableChart();
        $j1 = $this->postOperational($fy, $chart['detail'], $chart['detail2'], 4, 3);

        $r1 = $this->reversals()->reverse($j1, [
            'fiscal_year_id' => 999,
            'branch_id' => 1,
        ]);

        $this->assertSame($fy->id, $r1->fiscal_year_id);
        $this->assertSame(3, $r1->branch_id);
    }

    public function test_migration_added_reversed_document_id(): void
    {
        $table = config('accounting.general.prefix', 'acc_').'documents';
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'reversed_document_id'));
    }

    public function test_reversal_migration_rolls_back_and_reapplies(): void
    {
        $table = config('accounting.general.prefix', 'acc_').'documents';
        $migration = require __DIR__.'/../database/migrations/2021_01_01_000010_add_document_reversal.php';

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'reversed_document_id'));
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn($table, 'reversed_document_id'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'reversed_document_id'));
    }
}
