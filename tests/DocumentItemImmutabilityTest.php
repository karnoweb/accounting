<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Services\DocumentService;

class DocumentItemImmutabilityTest extends TestCase
{
    private function postedDocument(): array
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]);
        $posted = app(DocumentService::class)->post($document);

        return [$posted->fresh('items'), $chart];
    }

    public function test_update_posted_item_rejected(): void
    {
        [$document] = $this->postedDocument();
        $item = $document->items->first();

        $this->expectException(DocumentNotEditableException::class);
        $item->update(['description' => 'changed']);
    }

    public function test_change_posted_item_account_rejected(): void
    {
        [$document, $chart] = $this->postedDocument();
        $item = $document->items->first();

        $this->expectException(DocumentNotEditableException::class);
        $item->update(['account_id' => $chart['detail2']->id]);
    }

    public function test_change_posted_item_amount_rejected(): void
    {
        [$document] = $this->postedDocument();
        $item = $document->items->first();

        $this->expectException(DocumentNotEditableException::class);
        $item->update(['amount' => 999]);
    }

    public function test_delete_posted_item_rejected(): void
    {
        [$document] = $this->postedDocument();
        $item = $document->items->first();

        $this->expectException(DocumentNotEditableException::class);
        $item->delete();
    }

    public function test_draft_item_remains_editable(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 40),
        ]);

        $item = $document->items->first();
        $item->update(['description' => 'edited draft', 'amount' => 40]);

        $this->assertSame('edited draft', $item->fresh()->description);
    }

    public function test_posted_document_header_cannot_mutate(): void
    {
        [$document] = $this->postedDocument();

        $this->expectException(DocumentNotEditableException::class);
        $document->update(['description' => 'nope']);
    }

    public function test_posted_document_cannot_be_deleted(): void
    {
        [$document] = $this->postedDocument();

        $this->expectException(DocumentNotEditableException::class);
        $document->delete();
    }

    public function test_cannot_add_item_to_posted_document(): void
    {
        [$document, $chart] = $this->postedDocument();

        $this->expectException(DocumentNotEditableException::class);
        DocumentItem::create([
            'document_id' => $document->id,
            'account_id' => $chart['detail']->id,
            'amount' => 1,
            'sign' => 1,
        ]);
    }
}
