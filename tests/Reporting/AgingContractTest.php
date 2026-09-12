<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Reporting\Aging\AgingFilters;
use Karnoweb\Accounting\Reporting\Aging\AgingOpenItem;
use Karnoweb\Accounting\Reporting\Aging\AgingSourceProvider;
use Karnoweb\Accounting\Reporting\Aging\AgingUnavailableException;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class AgingContractTest extends TestCase
{
    public function test_native_aging_is_unavailable_without_provider(): void
    {
        $this->expectException(AgingUnavailableException::class);
        $this->app->make(\Karnoweb\Accounting\Services\ReportService::class)->receivableAging([
            'as_of_date' => '2026-01-31',
        ]);
    }

    public function test_provider_can_supply_open_items_and_bucket_boundaries(): void
    {
        $asOf = '2026-01-31';
        $items = [
            $this->item(1, '2026-02-01', 0, '100', $asOf),
            $this->item(2, '2026-01-30', 1, '10', $asOf),
            $this->item(3, '2026-01-01', 30, '20', $asOf),
            $this->item(4, '2025-12-31', 31, '30', $asOf),
            $this->item(5, '2025-12-02', 60, '40', $asOf),
            $this->item(6, '2025-12-01', 61, '50', $asOf),
            $this->item(7, '2025-11-02', 90, '60', $asOf),
            $this->item(8, '2025-11-01', 91, '70', $asOf),
            $this->item(9, '2025-10-03', 120, '80', $asOf),
            $this->item(10, '2025-10-02', 121, '90', $asOf),
            $this->item(11, '2026-01-15', 16, '0', $asOf),
        ];

        $this->app->instance(AgingSourceProvider::class, new class($items) implements AgingSourceProvider {
            public function __construct(private array $items) {}

            public function queryOpenItems(AgingFilters $filters): iterable
            {
                return $this->items;
            }
        });

        $result = $this->app->make(\Karnoweb\Accounting\Services\ReportService::class)->receivableAging([
            'as_of_date' => $asOf,
            'per_page' => 50,
        ]);

        $this->assertSame(10, $result->summary['party_count']);
        $row = $result->data;
        $byParty = [];
        foreach ($row as $party) {
            $byParty[$party->partyId] = $party;
        }

        $this->assertAmount('100', $byParty[1]->current);
        $this->assertAmount('10', $byParty[2]->days1To30);
        $this->assertAmount('20', $byParty[3]->days1To30);
        $this->assertAmount('30', $byParty[4]->days31To60);
        $this->assertAmount('40', $byParty[5]->days31To60);
        $this->assertAmount('50', $byParty[6]->days61To90);
        $this->assertAmount('60', $byParty[7]->days61To90);
        $this->assertAmount('70', $byParty[8]->days91To120);
        $this->assertAmount('80', $byParty[9]->days91To120);
        $this->assertAmount('90', $byParty[10]->days120Plus);

        $sum = Amount::zero();
        foreach ($result->data as $party) {
            $sum = $sum->add($party->totalOutstanding);
        }
        $this->assertTrue($sum->equals($result->summary['total_outstanding']));
    }

    private function item(int $id, string $due, int $days, string $outstanding, string $asOf): AgingOpenItem
    {
        return new AgingOpenItem(
            sourceType: 'invoice',
            sourceId: $id,
            documentNumber: (string) $id,
            documentDate: $due,
            dueDate: $due,
            originalAmount: $outstanding,
            settledAmount: '0.00',
            outstandingAmount: $outstanding,
            daysOverdue: $days,
            agingBucket: 'n/a',
            branchId: 1,
            costCenterId: null,
            partyId: $id,
        );
    }

    private function assertAmount(string $expected, string $actual): void
    {
        $this->assertTrue(Amount::of($expected)->equals($actual), "{$actual} !== {$expected}");
    }
}
