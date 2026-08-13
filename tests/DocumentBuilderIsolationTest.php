<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Services\DocumentBuilder;

class DocumentBuilderIsolationTest extends TestCase
{
    public function test_builder_a_does_not_leak_into_builder_b(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $builderA = Accounting::document()
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 10)
            ->credit($chart['detail2'], 10);

        $builderB = Accounting::document()
            ->type('adjustment')
            ->date('2025-06-02')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 99)
            ->credit($chart['detail2'], 99);

        $this->assertCount(2, $builderA->toArray()['items']);
        $this->assertEquals(10.0, $builderA->toArray()['items'][0]['amount']);
        $this->assertEquals(99.0, $builderB->toArray()['items'][0]['amount']);
        $this->assertNotSame($builderA, $builderB);
    }

    public function test_save_resets_builder_state(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart();

        $builder = app(DocumentBuilder::class)
            ->type('adjustment')
            ->date('2025-06-01')
            ->fiscalYear($fy)
            ->debit($chart['detail'], 12)
            ->credit($chart['detail2'], 12);

        $doc = $builder->save();
        $this->assertCount(2, $doc->items);
        $this->assertSame([], $builder->toArray()['items']);
    }

    public function test_container_resolves_fresh_builders(): void
    {
        $a = app(DocumentBuilder::class);
        $b = app(DocumentBuilder::class);
        $this->assertNotSame($a, $b);
    }
}
