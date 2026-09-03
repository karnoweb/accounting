<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InvalidPostingAccountException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;

class OpeningServiceTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function opening(): OpeningService
    {
        return app(OpeningService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function activeYear(): FiscalYear
    {
        return $this->years()->activate($this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]));
    }

    public function test_post_creates_posted_opening_and_completes_flag(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $this->assertFalse($this->opening()->isComplete($fy));

        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 150));

        $this->assertTrue($document->isPosted());
        $this->assertSame('opening', $document->type);
        $this->assertSame('2025-01-01', $document->date->toDateString());
        $this->assertNull($document->branch_id);
        $this->assertSame('opening:'.$fy->id.':branch:none', $document->idempotency_key);
        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertTrue($this->opening()->isComplete($fy->id));
        $this->assertSame(1, Document::query()->count());
        $this->assertEqualsWithDelta(
            150.0,
            Accounting::balance()->getBalance($chart['detail'], $fy),
            0.001
        );
    }

    public function test_facade_resolves_opening_service(): void
    {
        $this->assertInstanceOf(OpeningService::class, Accounting::opening());
        $this->assertSame($this->opening(), Accounting::opening());
    }

    public function test_repeat_post_is_idempotent(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $items = $this->balancedItems($chart['detail'], $chart['detail2'], 80);

        $first = $this->opening()->post($fy, $items);
        $second = $this->opening()->post($fy, $items);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->count());
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_unbalanced_items_leave_year_untouched(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        try {
            $this->opening()->post($fy, [
                ['account_id' => $chart['detail']->id, 'amount' => 100, 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => 40, 'sign' => -1],
            ]);
            $this->fail('Unbalanced opening must be rejected');
        } catch (UnbalancedDocumentException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(0, Document::query()->count());
            $this->assertFalse($this->opening()->isComplete($fy));
        }
    }

    public function test_parent_account_is_rejected(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidPostingAccountException::class);

        $this->opening()->post($fy, $this->balancedItems($chart['subsidiary'], $chart['detail2'], 10));
    }

    public function test_income_account_is_rejected(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $income = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Sales',
            'type' => AccountType::INCOME,
        ]);

        try {
            $this->opening()->post($fy, $this->balancedItems($chart['detail'], $income, 25));
            $this->fail('Temporary accounts must be refused');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_expense_account_is_rejected(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $expense = app(AccountService::class)->create([
            'parent_id' => $chart['subsidiary']->id,
            'title' => 'Rent',
            'type' => AccountType::EXPENSE,
        ]);

        $this->expectException(FiscalYearStateException::class);

        $this->opening()->post($fy, $this->balancedItems($expense, $chart['detail2'], 25));
    }

    public function test_zero_amount_lines_are_omitted(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $document = $this->opening()->post($fy, [
            ['account_id' => $chart['detail']->id, 'amount' => 60, 'sign' => 1],
            ['account_id' => $chart['detail']->id, 'amount' => 0, 'sign' => 1],
            ['account_id' => $chart['detail2']->id, 'amount' => 60, 'sign' => -1],
        ]);

        $this->assertCount(2, $document->items);
        $this->assertTrue($document->isPosted());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);

        $this->opening()->post($fy, [
            ['account_id' => $chart['detail']->id, 'amount' => -50, 'sign' => 1],
            ['account_id' => $chart['detail2']->id, 'amount' => 50, 'sign' => -1],
        ]);
    }

    public function test_draft_year_is_rejected(): void
    {
        $fy = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $chart = $this->createPostableChart();

        $this->expectException(FiscalYearStateException::class);

        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 10));
    }

    public function test_closed_year_is_rejected(): void
    {
        $fy = $this->years()->close($this->activeYear());
        $chart = $this->createPostableChart();

        $this->expectException(ClosedFiscalYearException::class);

        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 10));
    }

    public function test_posted_operational_document_blocks_opening(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]));

        try {
            $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 40));
            $this->fail('Operational activity must block opening');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(1, Document::query()->count());
            $this->assertSame('adjustment', Document::query()->first()->type);
        }
    }

    public function test_explicit_branch_id_is_persisted(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $document = $this->opening()->post(
            $fy,
            $this->balancedItems($chart['detail'], $chart['detail2'], 20),
            3
        );

        $this->assertSame(3, $document->branch_id);
        $this->assertSame('opening:'.$fy->id.':branch:3', $document->idempotency_key);
    }

    public function test_explicit_null_branch_id_is_persisted(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $document = $this->opening()->post(
            $fy,
            $this->balancedItems($chart['detail'], $chart['detail2'], 20),
            null
        );

        $this->assertNull($document->branch_id);
        $this->assertSame('opening:'.$fy->id.':branch:none', $document->idempotency_key);
    }

    public function test_fy_scoped_full_year_treats_opening_journal_as_period(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 200));

        $row = Accounting::report()->trialBalanceDetailed($fy)->find($chart['detail']->id);

        $this->assertEqualsWithDelta(0.0, $row->openingDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $row->periodDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $row->endingDebit, 0.001);
    }

    public function test_fy_scoped_mid_year_includes_opening_journal_in_opening_balance(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 200));

        $row = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($fy)->from('2025-02-01')->to('2025-12-31')
        )->find($chart['detail']->id);

        $this->assertEqualsWithDelta(200.0, $row->openingDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $row->periodDebit, 0.001);
    }

    public function test_failed_post_does_not_set_opening_done(): void
    {
        $fy = $this->activeYear();

        try {
            $this->opening()->post($fy, []);
            $this->fail('Empty items must fail');
        } catch (InvalidArgumentException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_already_complete_without_matching_opening_is_rejected(): void
    {
        $fy = $this->years()->completeOpening($this->activeYear());
        $chart = $this->createPostableChart();

        $this->expectException(FiscalYearStateException::class);

        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 10));
    }

    // --- saveDraft() / confirm() / find() -----------------------------------------

    public function test_save_draft_creates_draft_and_leaves_opening_done_false(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $draft = $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 100));

        $this->assertSame(DocumentStatus::DRAFT, $draft->status);
        $this->assertSame('opening', $draft->type);
        $this->assertSame('opening:'.$fy->id.':branch:none', $draft->idempotency_key);
        $this->assertFalse($fy->fresh()->opening_done);
        $this->assertSame(1, Document::query()->where('type', 'opening')->count());
    }

    public function test_save_draft_may_be_unbalanced(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $draft = $this->opening()->saveDraft($fy, [
            ['account_id' => $chart['detail']->id, 'amount' => 100, 'sign' => 1],
            ['account_id' => $chart['detail2']->id, 'amount' => 40, 'sign' => -1],
        ]);

        $this->assertSame(DocumentStatus::DRAFT, $draft->status);
        $this->assertFalse($draft->isBalanced());
        $this->assertFalse($fy->fresh()->opening_done);
    }

    public function test_save_draft_called_twice_replaces_items_in_place(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $first = $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 50));
        $second = $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 90));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->where('type', 'opening')->count());
        $this->assertSame(2, $second->items->count());
        $this->assertEqualsWithDelta(90.0, (float) $second->items->where('sign', 1)->sum('amount'), 0.001);
    }

    public function test_confirm_posts_balances_and_completes_opening(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 70));

        $posted = $this->opening()->confirm($fy);

        $this->assertTrue($posted->isPosted());
        $this->assertSame('opening', $posted->type);
        $this->assertSame('opening:'.$fy->id.':branch:none', $posted->idempotency_key);
        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_confirm_without_draft_is_rejected(): void
    {
        $fy = $this->activeYear();

        $this->expectException(FiscalYearStateException::class);

        $this->opening()->confirm($fy);
    }

    public function test_confirm_unbalanced_draft_fails(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, [
            ['account_id' => $chart['detail']->id, 'amount' => 100, 'sign' => 1],
            ['account_id' => $chart['detail2']->id, 'amount' => 40, 'sign' => -1],
        ]);

        try {
            $this->opening()->confirm($fy);
            $this->fail('Unbalanced draft must not confirm');
        } catch (UnbalancedDocumentException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(DocumentStatus::DRAFT, Document::query()->first()->status);
        }
    }

    public function test_confirm_with_posted_operational_document_fails(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 40));
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]));

        try {
            $this->opening()->confirm($fy);
            $this->fail('Operational activity must block confirm');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertSame(DocumentStatus::DRAFT, Document::query()->where('type', 'opening')->first()->status);
        }
    }

    public function test_confirm_is_idempotent_when_already_posted(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 60));
        $first = $this->opening()->confirm($fy);

        $second = $this->opening()->confirm($fy);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->count());
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_save_draft_rejects_when_posted_opening_already_exists_for_bucket(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 30));

        $this->expectException(FiscalYearStateException::class);

        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 55));
    }

    public function test_find_returns_null_when_nothing_exists(): void
    {
        $fy = $this->activeYear();

        $this->assertNull($this->opening()->find($fy));
    }

    public function test_find_returns_draft_before_confirm_and_posted_after(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 65));

        $draft = $this->opening()->find($fy);
        $this->assertNotNull($draft);
        $this->assertSame(DocumentStatus::DRAFT, $draft->status);

        $this->opening()->confirm($fy);

        $posted = $this->opening()->find($fy);
        $this->assertNotNull($posted);
        $this->assertTrue($posted->isPosted());
        $this->assertSame($draft->id, $posted->id);
    }

    public function test_find_respects_branch_bucket(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();
        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 12), 3);

        $this->assertNull($this->opening()->find($fy));
        $this->assertNull($this->opening()->find($fy, 7));
        $this->assertNotNull($this->opening()->find($fy, 3));
    }

    public function test_post_one_shot_is_equivalent_to_save_draft_then_confirm(): void
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 45));

        $this->assertTrue($document->isPosted());
        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertSame($document->id, $this->opening()->find($fy)->id);
    }
}
