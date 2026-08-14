<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;

class FiscalYearOpeningLifecycleTest extends TestCase
{
    private function service(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function draftYear(): FiscalYear
    {
        return $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
    }

    private function activeYear(): FiscalYear
    {
        return $this->service()->activate($this->draftYear());
    }

    /**
     * @return array{fy: FiscalYear, debit: \Karnoweb\Accounting\Models\Account, credit: \Karnoweb\Accounting\Models\Account}
     */
    private function activeYearWithChart(): array
    {
        $fy = $this->activeYear();
        $chart = $this->createPostableChart();

        return [
            'fy' => $fy,
            'debit' => $chart['detail'],
            'credit' => $chart['detail2'],
        ];
    }

    private function snapshot(FiscalYear $fy): array
    {
        return [
            'status' => $fy->status,
            'is_current' => $fy->is_current,
            'start_date' => $fy->start_date->toDateString(),
            'end_date' => $fy->end_date->toDateString(),
            'opened_at' => $fy->opened_at?->toDateTimeString(),
            'closed_at' => $fy->closed_at?->toDateTimeString(),
            'title' => $fy->title,
        ];
    }

    public function test_complete_opening_sets_flag_on_active_year(): void
    {
        $fy = $this->activeYear();
        $before = $this->snapshot($fy);
        $documentCount = Document::query()->count();

        $completed = $this->service()->completeOpening($fy);

        $this->assertTrue($completed->opening_done);
        $this->assertSame(FiscalYearStatus::ACTIVE, $completed->status);
        $this->assertTrue($completed->is_current);
        $this->assertSame($before, $this->snapshot($completed));
        $this->assertSame($documentCount, Document::query()->count());
    }

    public function test_complete_opening_is_idempotent(): void
    {
        $fy = $this->service()->completeOpening($this->activeYear());
        $before = $this->snapshot($fy);
        $openedAt = $fy->opened_at->toDateTimeString();

        $again = $this->service()->completeOpening($fy->id);

        $this->assertTrue($again->opening_done);
        $this->assertSame($before, $this->snapshot($again));
        $this->assertSame($openedAt, $again->opened_at->toDateTimeString());
        $this->assertSame(0, Document::query()->count());
    }

    public function test_complete_opening_rejects_draft(): void
    {
        $fy = $this->draftYear();

        $this->expectException(FiscalYearStateException::class);

        $this->service()->completeOpening($fy);
    }

    public function test_complete_opening_rejects_closed(): void
    {
        $fy = $this->service()->close($this->activeYear());

        $this->expectException(FiscalYearStateException::class);

        $this->service()->completeOpening($fy);
    }

    public function test_complete_opening_does_not_create_documents(): void
    {
        $fy = $this->activeYear();

        $this->service()->completeOpening($fy);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentItem::query()->count());
        $this->assertSame(0, Document::query()->where('type', 'opening')->count());
    }

    public function test_model_complete_opening_delegates_to_service(): void
    {
        $fy = $this->activeYear();

        $completed = $fy->completeOpening();

        $this->assertTrue($completed->opening_done);
        $this->assertTrue($completed->isActive());
    }

    public function test_revert_opening_clears_flag_when_no_posted_opening(): void
    {
        $fy = $this->service()->completeOpening($this->activeYear());
        $before = $this->snapshot($fy);

        $reverted = $this->service()->revertOpening($fy);

        $this->assertFalse($reverted->opening_done);
        $this->assertSame($before, $this->snapshot($reverted));
        $this->assertSame(0, Document::query()->count());
    }

    public function test_revert_opening_already_false_is_idempotent(): void
    {
        $fy = $this->activeYear();
        $this->assertFalse($fy->opening_done);
        $before = $this->snapshot($fy);

        $again = $this->service()->revertOpening($fy);

        $this->assertFalse($again->opening_done);
        $this->assertSame($before, $this->snapshot($again));
    }

    public function test_revert_opening_rejects_draft(): void
    {
        $this->expectException(FiscalYearStateException::class);

        $this->service()->revertOpening($this->draftYear());
    }

    public function test_revert_opening_rejects_closed(): void
    {
        $fy = $this->service()->completeOpening($this->activeYear());
        $fy = $this->service()->close($fy);
        $this->assertTrue($fy->opening_done);

        $this->expectException(FiscalYearStateException::class);

        $this->service()->revertOpening($fy);
    }

    public function test_posted_opening_document_blocks_revert(): void
    {
        $context = $this->activeYearWithChart();
        $fy = $this->service()->completeOpening($context['fy']);
        $document = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2025-01-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($context['debit'], $context['credit'], 50),
        ]));
        $itemCount = DocumentItem::query()->count();

        try {
            $this->service()->revertOpening($fy);
            $this->fail('Posted opening document must block revertOpening()');
        } catch (FiscalYearStateException $e) {
            $this->assertTrue($fy->fresh()->opening_done);
            $this->assertSame(DocumentStatus::POSTED, $document->fresh()->status);
            $this->assertSame(1, Document::query()->count());
            $this->assertSame($itemCount, DocumentItem::query()->count());
            $this->assertSame($document->id, Document::query()->first()->id);
        }
    }

    public function test_voided_opening_document_does_not_block_revert(): void
    {
        $context = $this->activeYearWithChart();
        $fy = $this->service()->completeOpening($context['fy']);
        $document = $this->documents()->post($this->documents()->create([
            'type' => 'opening',
            'date' => '2025-01-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($context['debit'], $context['credit'], 40),
        ]));
        $document->void('correction');
        $notes = $document->fresh()->notes;

        $reverted = $this->service()->revertOpening($fy);

        $this->assertFalse($reverted->opening_done);
        $this->assertSame(DocumentStatus::VOIDED, $document->fresh()->status);
        $this->assertSame($notes, $document->fresh()->notes);
        $this->assertSame(1, Document::query()->count());
        $this->assertSame($document->id, Document::query()->first()->id);
    }

    public function test_posted_operational_document_does_not_block_revert(): void
    {
        $context = $this->activeYearWithChart();
        $fy = $this->service()->completeOpening($context['fy']);
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($context['debit'], $context['credit'], 10),
        ]));

        $reverted = $this->service()->revertOpening($fy);

        $this->assertFalse($reverted->opening_done);
        $this->assertSame(1, Document::query()->count());
        $this->assertSame('adjustment', Document::query()->first()->type);
        $this->assertSame(DocumentStatus::POSTED, Document::query()->first()->status);
    }

    public function test_model_revert_opening_delegates_to_service(): void
    {
        $fy = $this->activeYear()->completeOpening();

        $reverted = $fy->revertOpening();

        $this->assertFalse($reverted->opening_done);
        $this->assertTrue($reverted->isActive());
    }

    public function test_create_and_update_still_reject_opening_done(): void
    {
        $this->expectException(FiscalYearStateException::class);

        $this->service()->create([
            'title' => 'FY 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'opening_done' => true,
        ]);
    }

    public function test_update_still_rejects_opening_done(): void
    {
        $fy = $this->activeYear();

        $this->expectException(FiscalYearStateException::class);

        $this->service()->update($fy, ['opening_done' => true]);
    }

    public function test_complete_and_revert_share_transactional_lock_path(): void
    {
        $fy = $this->activeYear();

        DB::transaction(function () use ($fy) {
            $completed = $this->service()->completeOpening($fy);
            $this->assertTrue($completed->opening_done);

            $reverted = $this->service()->revertOpening($completed);
            $this->assertFalse($reverted->opening_done);

            $again = $this->service()->completeOpening($reverted->id);
            $this->assertTrue($again->opening_done);
        });

        $fresh = $fy->fresh();
        $this->assertTrue($fresh->opening_done);
        $this->assertSame(FiscalYearStatus::ACTIVE, $fresh->status);
        $this->assertTrue($fresh->is_current);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_close_preserves_opening_done_after_complete(): void
    {
        $fy = $this->service()->completeOpening($this->activeYear());
        $closed = $this->service()->close($fy);

        $this->assertTrue($closed->opening_done);
        $this->assertTrue($closed->isClosed());
        $this->assertFalse($closed->is_current);
        $this->assertSame(0, Document::query()->where('type', 'opening')->count());
    }
}
