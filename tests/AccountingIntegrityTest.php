<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountingPeriodStatus;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Events\DocumentCreated;
use Karnoweb\Accounting\Events\DocumentPosted;
use Karnoweb\Accounting\Exceptions\AccountingPeriodStateException;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\AccountingPeriod;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Services\DocumentService;
use RuntimeException;

class AccountingIntegrityTest extends TestCase
{
    public function test_eloquent_status_update_cannot_post_a_draft(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->expectException(DocumentNotEditableException::class);
        $document->update(['status' => DocumentStatus::POSTED]);
    }

    public function test_create_rejects_posted_status(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'status' => DocumentStatus::POSTED,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);
    }

    public function test_mark_as_posted_delegates_to_canonical_post(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 15),
        ]);

        $posted = $document->markAsPosted();

        $this->assertTrue($posted->isPosted());
        $this->assertEqualsWithDelta(15.0, Accounting::balance()->getBalance($chart['detail'], $fy), 0.001);
    }

    public function test_zero_amount_line_is_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => 0, 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => 10, 'sign' => -1],
            ],
        ]);
    }

    public function test_invalid_sign_is_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => 10, 'sign' => 0],
                ['account_id' => $chart['detail2']->id, 'amount' => 10, 'sign' => -1],
            ],
        ]);
    }

    public function test_builder_rejects_zero_amount(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidArgumentException::class);
        Accounting::document()
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 0)
            ->credit($chart['detail2'], 10)
            ->save();
    }

    public function test_closed_fiscal_year_cannot_be_reopened_via_model(): void
    {
        $fy = $this->createActiveFiscalYear();
        $fy->update(['status' => FiscalYearStatus::CLOSED]);

        $this->expectException(FiscalYearStateException::class);
        $fy->update(['status' => FiscalYearStatus::ACTIVE]);
    }

    public function test_closed_period_cannot_be_reopened_via_model(): void
    {
        $fy = $this->createActiveFiscalYear();
        $period = AccountingPeriod::query()->forFiscalYear($fy)->firstOrFail();
        $period->update([
            'status' => AccountingPeriodStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->expectException(AccountingPeriodStateException::class);
        $period->fresh()->update(['status' => AccountingPeriodStatus::OPEN]);
    }

    public function test_account_parent_cycle_is_rejected(): void
    {
        $chart = $this->createPostableChart();
        $chart['detail']->update(['parent_id' => $chart['detail2']->id]);

        $this->expectException(InvalidAccountHierarchyException::class);
        $chart['detail2']->update(['parent_id' => $chart['detail']->id]);
    }

    public function test_account_branch_cannot_change_after_journal_lines(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 8),
        ]);

        $this->expectException(InvalidAccountHierarchyException::class);
        $chart['detail']->update(['branch_id' => 9]);
    }

    public function test_parent_with_children_cannot_become_postable(): void
    {
        $chart = $this->createPostableChart();

        $this->expectException(InvalidAccountHierarchyException::class);
        $chart['subsidiary']->update(['allow_direct_posting' => true]);
    }

    public function test_create_rolls_back_when_created_event_fails(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $documents = Document::query()->count();
        $items = DocumentItem::query()->count();

        Event::listen(DocumentCreated::class, function (): void {
            throw new RuntimeException('injected-create-failure');
        });

        try {
            app(DocumentService::class)->create([
                'type' => 'adjustment',
                'date' => '2025-06-01',
                'fiscal_year_id' => $fy->id,
                'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 20),
            ]);
            $this->fail('Create should not succeed after injected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('injected-create-failure', $e->getMessage());
        } finally {
            Event::forget(DocumentCreated::class);
        }

        $this->assertSame($documents, Document::query()->count());
        $this->assertSame($items, DocumentItem::query()->count());
    }

    public function test_post_rolls_back_when_posted_event_fails(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 22),
        ]);

        Event::listen(DocumentPosted::class, function (): void {
            throw new RuntimeException('injected-post-failure');
        });

        try {
            app(DocumentService::class)->post($document);
            $this->fail('Post should not succeed after injected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('injected-post-failure', $e->getMessage());
        } finally {
            Event::forget(DocumentPosted::class);
        }

        $document = $document->fresh();
        $this->assertSame(DocumentStatus::DRAFT, $document->status);
        $this->assertNull($document->posted_at);
        $this->assertEqualsWithDelta(0.0, Accounting::balance()->getBalance($chart['detail'], $fy), 0.001);
    }

    public function test_void_cannot_change_unrelated_header_fields(): void
    {
        $fy = $this->createActiveFiscalYear();
        $other = $this->createActiveFiscalYear('FY 2026', '2026-01-01', '2026-12-31', false);
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 9),
        ]);
        $posted = app(DocumentService::class)->post($document);

        $this->expectException(DocumentNotEditableException::class);
        $posted->update([
            'status' => DocumentStatus::VOIDED,
            'fiscal_year_id' => $other->id,
        ]);
    }

    public function test_duplicate_post_is_rejected_and_leaves_single_posted_document(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 11),
        ]);

        $first = app(DocumentService::class)->post($document);
        $this->assertTrue($first->isPosted());

        $this->expectException(\Exception::class);
        app(DocumentService::class)->post($first);
    }
}
