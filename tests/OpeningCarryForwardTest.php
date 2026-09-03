<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InvalidPostingAccountException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;

class OpeningCarryForwardTest extends TestCase
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

    private function activateYear(string $title, string $start, string $end): FiscalYear
    {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
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
        $payload = [
            'type' => $type,
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $this->balancedItems($debit, $credit, $amount),
        ];

        return $this->documents()->post($this->documents()->create($payload));
    }

    /**
     * carryForward() only produces DRAFT openings; confirm() posts each bucket in
     * place. Helper to confirm every draft returned by carryForward() in this suite.
     *
     * @param  list<Document>  $drafts
     * @return \Illuminate\Support\Collection<int, Document> keyed by (int) branch_id
     */
    private function confirmDrafts(FiscalYear $target, array $drafts): \Illuminate\Support\Collection
    {
        return collect($drafts)
            ->map(fn (Document $draft) => $this->opening()->confirm($target, $draft->branch_id))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);
    }

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

    public function test_closed_source_and_active_consecutive_target_succeeds(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 120);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $drafts = $this->opening()->carryForward($source, $target);

        $this->assertCount(1, $drafts);
        $draft = $drafts[0];
        $this->assertSame(DocumentStatus::DRAFT, $draft->status);
        $this->assertSame('opening', $draft->type);
        $this->assertSame('2026-01-01', $draft->date->toDateString());
        $this->assertSame($target->id, $draft->fiscal_year_id);
        $this->assertNull($draft->branch_id);
        $this->assertSame('opening:'.$target->id.':branch:none', $draft->idempotency_key);
        $this->assertSame($source->id, $draft->meta['source_fiscal_year_id']);
        $this->assertSame('carry_forward', $draft->meta['operation']);
        $this->assertFalse($target->fresh()->opening_done);

        $document = $this->opening()->confirm($target);
        $this->assertTrue($document->isPosted());
        $this->assertTrue($target->fresh()->opening_done);

        $debit = $document->items->firstWhere('account_id', $chart['detail']->id);
        $credit = $document->items->firstWhere('account_id', $chart['detail2']->id);
        $this->assertSame(1, $debit->sign);
        $this->assertEqualsWithDelta(120.0, (float) $debit->amount, 0.001);
        $this->assertGreaterThan(0, (float) $debit->amount);
        $this->assertSame(-1, $credit->sign);
        $this->assertEqualsWithDelta(120.0, (float) $credit->amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $document->items->sum(fn ($item) => $item->amount * $item->sign), 0.001);
    }

    public function test_source_draft_is_rejected(): void
    {
        $source = $this->years()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_source_active_is_rejected(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $target = $this->years()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_missing_source_is_rejected(): void
    {
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $this->expectException(ModelNotFoundException::class);
        $this->opening()->carryForward(999_999, $target);
    }

    public function test_target_draft_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->years()->create([
            'title' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_target_closed_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->years()->close($this->activateYear('FY 2026', '2026-01-01', '2026-12-31'));

        $this->expectException(ClosedFiscalYearException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_gap_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->activateYear('FY 2026', '2026-01-02', '2026-12-31');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_overlapping_target_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = FiscalYear::withoutEvents(fn () => FiscalYear::create([
            'title' => 'Overlap',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'status' => FiscalYearStatus::ACTIVE,
            'is_current' => true,
        ]));

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_later_non_consecutive_year_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $this->years()->create([
            'title' => 'FY 2026 draft hole',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $target = $this->activateYear('FY 2027', '2027-01-01', '2027-12-31');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_earlier_year_is_rejected(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->activateYear('FY 2024', '2024-01-01', '2024-12-31');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_contra_signed_permanent_keeps_orientation(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail2'], $chart['detail'], 40);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $document = $this->opening()->carryForward($source, $target)[0];
        $asset = $document->items->firstWhere('account_id', $chart['detail']->id);
        $liability = $document->items->firstWhere('account_id', $chart['detail2']->id);

        $this->assertSame(-1, $asset->sign);
        $this->assertEqualsWithDelta(40.0, (float) $asset->amount, 0.001);
        $this->assertSame(1, $liability->sign);
        $this->assertEqualsWithDelta(40.0, (float) $liability->amount, 0.001);
    }

    public function test_income_and_expense_are_excluded_when_temporary_net_is_zero(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 90);
        $this->postInYear($source, $temps['expense'], $temps['income'], 25);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $document = $this->opening()->carryForward($source, $target)[0];
        $accountIds = $document->items->pluck('account_id')->all();

        $this->assertContains($chart['detail']->id, $accountIds);
        $this->assertContains($chart['detail2']->id, $accountIds);
        $this->assertNotContains($temps['income']->id, $accountIds);
        $this->assertNotContains($temps['expense']->id, $accountIds);
        $this->assertNotContains($chart['subsidiary']->id, $accountIds);
    }

    public function test_material_non_postable_permanent_rejects(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 70);
        $chart['detail']->update(['allow_direct_posting' => false]);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        try {
            $this->opening()->carryForward($source, $target);
            $this->fail('Non-postable permanent balance must reject carry-forward');
        } catch (InvalidPostingAccountException $e) {
            $this->assertFalse($target->fresh()->opening_done);
            $this->assertSame(0, Document::query()->where('type', 'opening')->count());
        }
    }

    public function test_temporary_residual_positive_rejects(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($source, $chart['detail'], $temps['income'], 55);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        try {
            $this->opening()->carryForward($source, $target);
            $this->fail('Positive P&L residual must reject');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($target->fresh()->opening_done);
            $this->assertSame(0, Document::query()->where('fiscal_year_id', $target->id)->count());
        }
    }

    public function test_temporary_residual_negative_rejects(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($source, $temps['expense'], $chart['detail2'], 33);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_two_branches_create_two_balanced_openings(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 25, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $drafts = $this->opening()->carryForward($source, $target);
        $this->assertCount(2, $drafts);

        $byBranch = collect($drafts)->keyBy(fn (Document $document) => (int) $document->branch_id);
        $this->assertTrue($byBranch->has(1));
        $this->assertTrue($byBranch->has(2));
        $this->assertSame('opening:'.$target->id.':branch:1', $byBranch[1]->idempotency_key);
        $this->assertSame('opening:'.$target->id.':branch:2', $byBranch[2]->idempotency_key);

        foreach ($drafts as $draft) {
            $this->assertEqualsWithDelta(0.0, (float) $draft->items->sum(fn ($item) => $item->amount * $item->sign), 0.001);
        }

        $documents = $this->confirmDrafts($target, $drafts);
        foreach ($documents as $document) {
            $this->assertTrue($document->isPosted());
        }

        $branch1 = LedgerQuery::make()->forFiscalYear($target)->branch(1)->forAccount($chart['detail'])->periodTotals();
        $branch2 = LedgerQuery::make()->forFiscalYear($target)->branch(2)->forAccount($chart['detail'])->periodTotals();
        $this->assertEqualsWithDelta(10.0, $branch1['balance'], 0.001);
        $this->assertEqualsWithDelta(25.0, $branch2['balance'], 0.001);
    }

    public function test_null_branch_stays_distinct_from_default(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 18, null);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $document = $this->opening()->carryForward($source, $target)[0];
        $this->assertNull($document->branch_id);
        $this->assertSame('opening:'.$target->id.':branch:none', $document->idempotency_key);
        $this->assertSame(1, Document::query()->where('type', 'opening')->whereNull('branch_id')->count());
        $this->assertSame(0, Document::query()->where('type', 'opening')->where('branch_id', 7)->count());
    }

    public function test_branch_with_only_cleared_temporaries_creates_no_document(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($source, $temps['expense'], $temps['income'], 12, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 44, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $documents = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $documents);
        $this->assertSame(2, (int) $documents[0]->branch_id);
        $this->assertSame(0, Document::query()->where('type', 'opening')->where('branch_id', 1)->count());
    }

    public function test_empty_source_completes_without_document(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $documents = $this->opening()->carryForward($source, $target);

        $this->assertSame([], $documents);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_zero_net_permanents_complete_without_document(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 50);
        $this->postInYear($source, $chart['detail2'], $chart['detail'], 50);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $documents = $this->opening()->carryForward($source, $target);
        $this->assertSame([], $documents);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertSame(0, Document::query()->where('type', 'opening')->count());
    }

    public function test_empty_carry_forward_repeat_creates_nothing(): void
    {
        $source = $this->years()->close($this->activateYear('FY 2025', '2025-01-01', '2025-12-31'));
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->opening()->carryForward($source, $target);

        $again = $this->opening()->carryForward($source, $target);
        $this->assertSame([], $again);
        $this->assertSame(0, Document::query()->count());
        $this->assertTrue($target->fresh()->opening_done);
    }

    public function test_successful_repeat_returns_same_document_ids(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 80);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $first = $this->opening()->carryForward($source, $target);
        $second = $this->opening()->carryForward($source, $target);

        $this->assertSame(collect($first)->pluck('id')->all(), collect($second)->pluck('id')->all());
        $this->assertSame(1, Document::query()->where('type', 'opening')->count());
    }

    public function test_inconsistent_existing_openings_are_rejected(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 80);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->opening()->post($target, $this->balancedItems($chart['detail'], $chart['detail2'], 15));

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_crash_recovery_completes_matching_openings(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 60);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $seed = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2026-01-01',
            'fiscal_year_id' => $target->id,
            'branch_id' => null,
            'idempotency_key' => 'opening:'.$target->id.':branch:none',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 60),
        ]));
        $this->assertFalse($target->fresh()->opening_done);

        $recovered = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $recovered);
        $this->assertSame($seed->id, $recovered[0]->id);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertSame(1, Document::query()->where('type', 'opening')->count());
    }

    /**
     * With draft-per-bucket openings, a partially-confirmed target is the normal,
     * expected state — not an error. A bucket already posted and matching the
     * recomputed plan is left untouched; a bucket with no matching posted opening
     * yet gets a fresh draft. `opening_done` stays false until every bucket is
     * confirmed.
     */
    public function test_partial_openings_create_remaining_drafts_without_error(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 20, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $seed = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2026-01-01',
            'fiscal_year_id' => $target->id,
            'branch_id' => 1,
            'idempotency_key' => 'opening:'.$target->id.':branch:1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]));

        $documents = collect($this->opening()->carryForward($source, $target))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $this->assertFalse($target->fresh()->opening_done);
        $this->assertCount(2, $documents);
        $this->assertSame($seed->id, $documents[1]->id);
        $this->assertTrue($documents[1]->isPosted());
        $this->assertSame(DocumentStatus::DRAFT, $documents[2]->status);
        $this->assertSame(2, Document::query()->where('type', 'opening')->count());

        $confirmed = $this->opening()->confirm($target, 2);
        $this->assertTrue($confirmed->isPosted());
        $this->assertTrue($target->fresh()->opening_done);
    }

    /**
     * A bucket already posted with items that do NOT match the recomputed plan is
     * a genuine inconsistency and still fails the whole call, leaving the other
     * buckets untouched.
     */
    public function test_mismatched_posted_opening_still_rejects(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 20, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $seed = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2026-01-01',
            'fiscal_year_id' => $target->id,
            'branch_id' => 1,
            'idempotency_key' => 'opening:'.$target->id.':branch:1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 999),
        ]));

        try {
            $this->opening()->carryForward($source, $target);
            $this->fail('Mismatched posted opening must not be repaired');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($target->fresh()->opening_done);
            $this->assertSame(1, Document::query()->where('type', 'opening')->count());
            $this->assertSame($seed->id, Document::query()->where('type', 'opening')->value('id'));
        }
    }

    public function test_posted_operational_document_rejects(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 40);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->postInYear($target, $chart['detail'], $chart['detail2'], 5, null, '2026-02-01');

        $this->expectException(FiscalYearStateException::class);
        $this->opening()->carryForward($source, $target);
    }

    public function test_voided_opening_key_can_be_reused(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 40);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $voided = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2026-01-01',
            'fiscal_year_id' => $target->id,
            'branch_id' => null,
            'idempotency_key' => 'opening:'.$target->id.':branch:none',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]));
        $voided->void('redo');

        $recreatedDrafts = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $recreatedDrafts);
        $this->assertSame(DocumentStatus::DRAFT, $recreatedDrafts[0]->status);
        $this->assertSame('opening:'.$target->id.':branch:none', $recreatedDrafts[0]->idempotency_key);
        $this->assertFalse($target->fresh()->opening_done);

        $confirmed = $this->opening()->confirm($target);
        $this->assertTrue($confirmed->isPosted());
        $this->assertSame('opening:'.$target->id.':branch:none', $confirmed->idempotency_key);
        $this->assertTrue($target->fresh()->opening_done);
    }

    public function test_fy_scoped_reporting_does_not_double_count(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 200);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->opening()->confirm($target, $this->opening()->carryForward($source, $target)[0]->branch_id);

        $fullYear = Accounting::report()->trialBalanceDetailed($target)->find($chart['detail']->id);
        $this->assertEqualsWithDelta(0.0, $fullYear->openingDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $fullYear->periodDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $fullYear->endingDebit, 0.001);

        $midYear = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->forFiscalYear($target)->from('2026-02-01')->to('2026-12-31')
        )->find($chart['detail']->id);
        $this->assertEqualsWithDelta(200.0, $midYear->openingDebit, 0.001);
        $this->assertEqualsWithDelta(0.0, $midYear->periodDebit, 0.001);

        $sourceRow = Accounting::report()->trialBalanceDetailed($source)->find($chart['detail']->id);
        $this->assertEqualsWithDelta(200.0, $sourceRow->periodDebit, 0.001);

        $lifetime = Accounting::report()->trialBalanceDetailed(
            LedgerQuery::make()->from('2026-01-01')->to('2026-12-31')
        )->find($chart['detail']->id);
        $this->assertEqualsWithDelta(200.0, $lifetime->openingDebit, 0.001);
        $this->assertEqualsWithDelta(200.0, $lifetime->periodDebit, 0.001);
        $this->assertEqualsWithDelta(400.0, $lifetime->endingDebit, 0.001);
    }

    public function test_failed_second_branch_rolls_back_first(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $temps = $this->temporaryAccounts($chart['subsidiary']);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 15, 1);
        $this->postInYear($source, $chart['detail'], $temps['income'], 15, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        try {
            $this->opening()->carryForward($source, $target);
            $this->fail('Unbalanced second branch must roll back the first');
        } catch (FiscalYearStateException $e) {
            $this->assertFalse($target->fresh()->opening_done);
            $this->assertSame(0, Document::query()->where('fiscal_year_id', $target->id)->count());
        }
    }

    public function test_source_permanent_balances_match_target_opening_via_ledger_query(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 88, null);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->opening()->confirm($target, $this->opening()->carryForward($source, $target)[0]->branch_id);

        $sourceTotals = LedgerQuery::make()->forFiscalYear($source)->branch(null)->periodTotalsByAccount();
        $targetTotals = LedgerQuery::make()->forFiscalYear($target)->branch(null)->periodTotalsByAccount();

        $this->assertEqualsWithDelta(
            (float) $sourceTotals[$chart['detail']->id]['debit'] - (float) $sourceTotals[$chart['detail']->id]['credit'],
            (float) $targetTotals[$chart['detail']->id]['debit'] - (float) $targetTotals[$chart['detail']->id]['credit'],
            0.001
        );
        $this->assertEqualsWithDelta(
            (float) $sourceTotals[$chart['detail2']->id]['debit'] - (float) $sourceTotals[$chart['detail2']->id]['credit'],
            (float) $targetTotals[$chart['detail2']->id]['debit'] - (float) $targetTotals[$chart['detail2']->id]['credit'],
            0.001
        );
    }
}
