<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

final class AgingPartyRow
{
    /**
     * @param  list<AgingOpenItem>  $items
     * @param  list<array<string, mixed>>  $branchBreakdown
     */
    public function __construct(
        public readonly int|string $partyId,
        public readonly string $partyCode,
        public readonly string $partyName,
        public readonly string $current,
        public readonly string $days1To30,
        public readonly string $days31To60,
        public readonly string $days61To90,
        public readonly string $days91To120,
        public readonly string $days120Plus,
        public readonly string $totalOutstanding,
        public readonly ?string $oldestDueDate,
        public readonly int $openItemCount,
        public readonly ?int $branchId,
        public readonly array $items = [],
        public readonly array $branchBreakdown = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'party_id' => $this->partyId,
            'party_code' => $this->partyCode,
            'party_name' => $this->partyName,
            'current' => $this->current,
            'days_1_30' => $this->days1To30,
            'days_31_60' => $this->days31To60,
            'days_61_90' => $this->days61To90,
            'days_91_120' => $this->days91To120,
            'days_120_plus' => $this->days120Plus,
            'total_outstanding' => $this->totalOutstanding,
            'oldest_due_date' => $this->oldestDueDate,
            'open_item_count' => $this->openItemCount,
            'branch_id' => $this->branchId,
            'items' => array_map(fn (AgingOpenItem $item) => $item->toArray(), $this->items),
            'branch_breakdown' => $this->branchBreakdown,
        ];
    }
}
