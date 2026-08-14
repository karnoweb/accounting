<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Exception;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Reporting\LedgerQuery;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;

class OpeningVoidLifecycleTest extends TestCase
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
        string $date = '2025-06-01'
    ): Document {
        return $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => $date,
            'fiscal_year_id' => $fy->id,
            'branch_id' => $branchId,
            'items' => $this->balancedItems($debit, $credit, $amount),
        ]));
    }

    private function assertOpeningContract(Document $document, FiscalYear $fy, ?int $branchId): void
    {
        $this->assertSame('opening', $document->type);
        $this->assertTrue($document->isPosted());
        $this->assertSame($fy->id, $document->fiscal_year_id);
        $this->assertSame($fy->start_date->toDateString(), $document->date->toDateString());
        $this->assertSame($branchId, $document->branch_id);
        $this->assertSame(
            'opening:'.$fy->id.':branch:'.($branchId ?? 'none'),
            $document->idempotency_key
        );
    }

    private function assertKeyFree(string $key): void
    {
        $this->assertFalse(
            Document::withTrashed()->where('idempotency_key', $key)->exists()
        );
    }

    public function test_void_manual_opening_reverts_flag_and_releases_key(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 150));
        $this->assertTrue($fy->fresh()->opening_done);

        $document->void('correction');

        $this->assertSame(DocumentStatus::VOIDED, $document->fresh()->status);
        $this->assertFalse($fy->fresh()->opening_done);
        $this->assertNull($document->fresh()->idempotency_key);
        $this->assertKeyFree('opening:'.$fy->id.':branch:none');
    }

    public function test_retry_manual_opening_reuses_deterministic_key(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $items = $this->balancedItems($chart['detail'], $chart['detail2'], 80);
        $first = $this->opening()->post($fy, $items);
        $first->void('retry');

        $second = $this->opening()->post($fy, $items);

        $this->assertNotSame($first->id, $second->id);
        $this->assertOpeningContract($second, $fy, null);
        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertSame(DocumentStatus::VOIDED, $first->fresh()->status);
    }

    public function test_void_single_branch_carry_forward_then_retry(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 40);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $first = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $first);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertOpeningContract($first[0], $target, null);
        $this->assertSame($source->id, $first[0]->meta['source_fiscal_year_id']);
        $this->assertSame('carry_forward', $first[0]->meta['operation']);

        $first[0]->void('redo');
        $this->assertFalse($target->fresh()->opening_done);
        $this->assertKeyFree('opening:'.$target->id.':branch:none');

        $second = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $second);
        $this->assertOpeningContract($second[0], $target, null);
        $this->assertSame($source->id, $second[0]->meta['source_fiscal_year_id']);
        $this->assertSame('carry_forward', $second[0]->meta['operation']);
        $this->assertTrue($target->fresh()->opening_done);
    }

    public function test_multi_branch_void_reverts_only_after_last_posted_opening(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 25, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $openings = collect($this->opening()->carryForward($source, $target))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);
        $this->assertTrue($target->fresh()->opening_done);

        $openings[1]->void('branch-1');
        $this->assertSame(DocumentStatus::POSTED, $openings[2]->fresh()->status);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertKeyFree('opening:'.$target->id.':branch:1');
        $this->assertSame('opening:'.$target->id.':branch:2', $openings[2]->fresh()->idempotency_key);

        $openings[2]->void('branch-2');
        $this->assertFalse($target->fresh()->opening_done);
        $this->assertKeyFree('opening:'.$target->id.':branch:2');
    }

    public function test_retry_carry_forward_after_all_branch_voids(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 25, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        foreach ($this->opening()->carryForward($source, $target) as $document) {
            $document->void('reset');
        }
        $this->assertFalse($target->fresh()->opening_done);

        $recreated = collect($this->opening()->carryForward($source, $target))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $this->assertCount(2, $recreated);
        $this->assertOpeningContract($recreated[1], $target, 1);
        $this->assertOpeningContract($recreated[2], $target, 2);
        $this->assertSame($source->id, $recreated[1]->meta['source_fiscal_year_id']);
        $this->assertSame('carry_forward', $recreated[1]->meta['operation']);
        $this->assertSame($source->id, $recreated[2]->meta['source_fiscal_year_id']);
        $this->assertSame('carry_forward', $recreated[2]->meta['operation']);
        $this->assertTrue($target->fresh()->opening_done);
    }

    public function test_void_opening_in_closed_year_does_not_reopen_or_revert(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 60));
        $this->assertTrue($fy->fresh()->opening_done);
        $closed = $this->years()->close($fy);

        $document->void('closed-year');

        $closed = $closed->fresh();
        $this->assertSame(FiscalYearStatus::CLOSED, $closed->status);
        $this->assertTrue($closed->opening_done);
        $this->assertFalse($closed->is_current);
        $this->assertSame(DocumentStatus::VOIDED, $document->fresh()->status);
        $this->assertNull($document->fresh()->idempotency_key);
    }

    public function test_operational_void_keeps_key_and_opening_done(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 20));
        $this->assertTrue($fy->fresh()->opening_done);

        $operational = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'op-keep-1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 5),
        ]));

        $operational->void('ops');

        $this->assertSame('op-keep-1', $operational->fresh()->idempotency_key);
        $this->assertTrue($fy->fresh()->opening_done);
        $this->assertTrue(
            Document::withTrashed()->where('idempotency_key', 'op-keep-1')->exists()
        );
    }

    public function test_duplicate_key_while_opening_still_posted(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $items = $this->balancedItems($chart['detail'], $chart['detail2'], 30);
        $first = $this->opening()->post($fy, $items);
        $again = $this->opening()->post($fy, $items);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Document::query()->where('type', 'opening')->where('status', DocumentStatus::POSTED->value)->count());

        $this->expectException(DuplicateIdempotencyKeyException::class);
        $this->documents()->create([
            'type' => 'opening',
            'date' => '2025-01-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'idempotency_key' => 'opening:'.$fy->id.':branch:none',
            'items' => $items,
        ]);
    }

    public function test_failed_retry_leaves_key_reusable(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $valid = $this->balancedItems($chart['detail'], $chart['detail2'], 45);
        $this->opening()->post($fy, $valid)->void('retry-later');
        $this->assertFalse($fy->fresh()->opening_done);

        try {
            $this->opening()->post($fy, [
                ['account_id' => $chart['detail']->id, 'amount' => 45, 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => 10, 'sign' => -1],
            ]);
            $this->fail('Unbalanced retry must fail');
        } catch (UnbalancedDocumentException) {
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertKeyFree('opening:'.$fy->id.':branch:none');
        }

        $retried = $this->opening()->post($fy, $valid);
        $this->assertOpeningContract($retried, $fy, null);
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_void_reverses_opening_ledger_effect(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 90));

        $this->assertEqualsWithDelta(90.0, Accounting::balance()->getBalance($chart['detail'], $fy), 0.001);
        $this->assertEqualsWithDelta(-90.0, Accounting::balance()->getBalance($chart['detail2'], $fy), 0.001);

        $document->void('reverse');

        $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($chart['detail'], $fy), 0.001);
        $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($chart['detail2'], $fy), 0.001);
    }

    public function test_void_one_branch_keeps_other_ledger_and_flag(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 10, 1);
        $this->postInYear($source, $chart['detail'], $chart['detail2'], 25, 2);
        $source = $this->years()->close($source);
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $openings = collect($this->opening()->carryForward($source, $target))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $openings[2]->void('branch-2-only');

        $branch1 = LedgerQuery::make()->forFiscalYear($target)->branch(1)->forAccount($chart['detail'])->periodTotals();
        $branch2 = LedgerQuery::make()->forFiscalYear($target)->branch(2)->forAccount($chart['detail'])->periodTotals();
        $this->assertEqualsWithDelta(10.0, $branch1['balance'], 0.001);
        $this->assertEqualsWithDelta(0.0, $branch2['balance'], 0.001);
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertKeyFree('opening:'.$target->id.':branch:2');
        $this->assertSame(DocumentStatus::POSTED, $openings[1]->fresh()->status);
    }

    public function test_only_opening_type_releases_key_and_reverts_flag(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->years()->completeOpening($fy);

        $adjustment = $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'not-an-opening',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 7),
        ]));
        $adjustment->void('not-opening');

        $this->assertSame('not-an-opening', $adjustment->fresh()->idempotency_key);
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_voided_opening_is_immutable_and_cannot_void_again(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $document = $this->opening()->post($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 12));
        $document->void('once');
        $voided = $document->fresh();

        try {
            $voided->update(['description' => 'no']);
            $this->fail('Voided opening must reject update');
        } catch (DocumentNotEditableException) {
            $this->assertSame(DocumentStatus::VOIDED, $voided->fresh()->status);
        }

        try {
            $voided->fresh()->post();
            $this->fail('Voided opening must reject post');
        } catch (Exception) {
            $this->assertSame(DocumentStatus::VOIDED, $voided->fresh()->status);
        }

        try {
            $voided->fresh()->void('twice');
            $this->fail('Second void must be rejected');
        } catch (Exception $e) {
            $this->assertSame(DocumentStatus::VOIDED, $voided->fresh()->status);
            $this->assertFalse($fy->fresh()->opening_done);
            $this->assertStringContainsString(
                __('accounting::accounting.messages.document_not_voidable'),
                $e->getMessage()
            );
        }
    }

    public function test_opening_done_false_with_only_voided_opening_allows_retry(): void
    {
        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $items = $this->balancedItems($chart['detail'], $chart['detail2'], 18);

        $orphan = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2025-01-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'idempotency_key' => 'opening:'.$fy->id.':branch:none',
            'items' => $items,
        ]));
        $this->assertFalse($fy->fresh()->opening_done);
        $orphan->void('never-completed');
        $this->assertFalse($fy->fresh()->opening_done);

        $posted = $this->opening()->post($fy, $items);
        $this->assertOpeningContract($posted, $fy, null);
        $this->assertTrue($fy->fresh()->opening_done);
    }
}
