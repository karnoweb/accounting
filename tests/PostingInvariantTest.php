<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\InactiveAccountException;
use Karnoweb\Accounting\Exceptions\InvalidPostingAccountException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Services\DocumentService;
use RuntimeException;

class PostingInvariantTest extends TestCase
{
    public function test_balanced_document_posts(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 250),
        ]);

        $posted = app(DocumentService::class)->post($document);

        $this->assertTrue($posted->isPosted());
        $this->assertCount(2, $posted->items);
        $this->assertEquals(250.0, (float) $posted->items->firstWhere('sign', 1)->amount);
        $this->assertEqualsWithDelta(
            250.0,
            Accounting::balance()->getBalance($chart['detail'], $fy),
            0.001
        );
        $this->assertEqualsWithDelta(
            -250.0,
            Accounting::balance()->getBalance($chart['detail2'], $fy),
            0.001
        );
    }

    public function test_unbalanced_document_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(UnbalancedDocumentException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => [
                ['account_id' => $chart['detail']->id, 'amount' => 100, 'sign' => 1],
                ['account_id' => $chart['detail2']->id, 'amount' => 50, 'sign' => -1],
            ],
        ]);
    }

    public function test_parent_account_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidPostingAccountException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['subsidiary'], $chart['detail2']),
        ]);
    }

    public function test_non_postable_account_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(InvalidPostingAccountException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['group'], $chart['detail2']),
        ]);
    }

    public function test_inactive_account_rejected(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $chart['detail']->update(['is_active' => false]);

        $this->expectException(InactiveAccountException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }

    public function test_valid_detail_account_accepted_via_builder(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $doc = Accounting::document()
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 80)
            ->credit($chart['detail2'], 80)
            ->post();

        $this->assertSame(DocumentStatus::POSTED, $doc->status);
        $this->assertTrue($doc->isBalanced());
    }

    public function test_document_model_post_uses_same_rules_as_service(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);

        $itemId = $document->items->first()->id;
        DB::table($document->items()->getRelated()->getTable())
            ->where('id', $itemId)
            ->update(['account_id' => $chart['group']->id]);

        $document->refresh()->load('items.account');

        $this->expectException(InvalidPostingAccountException::class);
        $document->post();
    }

    public function test_posting_outside_fiscal_year_fails(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $this->expectException(RuntimeException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2024-12-31',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }

    public function test_closed_fiscal_year_fails(): void
    {
        $fy = $this->createActiveFiscalYear();
        $fy->update(['status' => FiscalYearStatus::CLOSED]);
        $chart = $this->createPostableChart();

        $this->expectException(ClosedFiscalYearException::class);

        app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2']),
        ]);
    }
}
