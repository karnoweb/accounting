<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Exceptions\DuplicateIdempotencyKeyException;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Services\DocumentService;

class NumberingAndIdempotencyTest extends TestCase
{
    public function test_sequential_numbering_within_fiscal_year(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $first = $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-10',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
        $second = $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-11',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 2),
        ]);

        $this->assertSame(1, (int) $first->number);
        $this->assertSame(2, (int) $second->number);
    }

    public function test_fiscal_year_numbering_is_isolated(): void
    {
        $fy2025 = $this->createActiveFiscalYear('FY2025', '2025-01-01', '2025-12-31', true);
        $fy2026 = \Karnoweb\Accounting\Models\FiscalYear::create([
            'title' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => \Karnoweb\Accounting\Enums\FiscalYearStatus::ACTIVE,
            'is_current' => false,
        ]);
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $a = $service->create([
            'type' => 'adjustment',
            'date' => '2025-02-01',
            'fiscal_year_id' => $fy2025->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
        $b = $service->create([
            'type' => 'adjustment',
            'date' => '2026-02-01',
            'fiscal_year_id' => $fy2026->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);

        $this->assertSame(1, (int) $a->number);
        $this->assertSame(1, (int) $b->number);
    }

    public function test_duplicate_manual_number_is_prevented(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-10',
            'fiscal_year_id' => $fy->id,
            'number' => 42,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-11',
            'fiscal_year_id' => $fy->id,
            'number' => 42,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
    }

    public function test_concurrent_allocation_produces_unique_numbers(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $numbers = [];
        for ($i = 0; $i < 8; $i++) {
            DB::transaction(function () use ($service, $fy, $chart, &$numbers) {
                $doc = $service->create([
                    'type' => 'adjustment',
                    'date' => '2025-05-01',
                    'fiscal_year_id' => $fy->id,
                    'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
                ]);
                $numbers[] = (int) $doc->number;
            });
        }

        $this->assertCount(8, array_unique($numbers));
        $this->assertSame(range(1, 8), $numbers);
    }

    public function test_duplicate_idempotency_key_fails(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-10',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'pay-1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);

        $this->expectException(DuplicateIdempotencyKeyException::class);

        $service->create([
            'type' => 'adjustment',
            'date' => '2025-01-11',
            'fiscal_year_id' => $fy->id,
            'idempotency_key' => 'pay-1',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
    }

    public function test_same_source_can_create_multiple_documents_without_idempotency_key(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();
        $service = app(DocumentService::class);

        $a = $service->create([
            'type' => 'sale',
            'date' => '2025-01-10',
            'fiscal_year_id' => $fy->id,
            'source_type' => 'order',
            'source_id' => 7,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 1),
        ]);
        $b = $service->create([
            'type' => 'sale',
            'date' => '2025-01-11',
            'fiscal_year_id' => $fy->id,
            'source_type' => 'order',
            'source_id' => 7,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 2),
        ]);

        $this->assertNotEquals($a->id, $b->id);
        $this->assertSame(2, Document::where('source_type', 'order')->where('source_id', 7)->count());
    }
}
