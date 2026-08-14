<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Services\DocumentService;

class DocumentBranchIdTest extends TestCase
{
    public function test_omitted_branch_id_uses_configured_default(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->assertSame(7, $document->branch_id);
    }

    public function test_omitted_branch_id_is_null_when_branch_disabled(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->assertNull($document->branch_id);
    }

    public function test_explicit_branch_id_is_persisted(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 3,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->assertSame(3, $document->branch_id);
    }

    public function test_explicit_null_branch_id_is_persisted(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->assertNull($document->branch_id);
    }

    public function test_explicit_null_branch_id_does_not_affect_posting(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = app(DocumentService::class)->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => null,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 25),
        ]);

        $posted = app(DocumentService::class)->post($document);

        $this->assertTrue($posted->isPosted());
        $this->assertNull($posted->fresh()->branch_id);
        $this->assertEqualsWithDelta(
            25.0,
            Accounting::balance()->getBalance($chart['detail'], $fy),
            0.001
        );
    }

    public function test_fluent_builder_omitted_branch_uses_default(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
        ]);

        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $document = Accounting::document()
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 10)
            ->credit($chart['detail2'], 10)
            ->save();

        $this->assertSame(7, $document->branch_id);
    }
}
