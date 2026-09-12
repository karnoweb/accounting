<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

use Karnoweb\Accounting\Reporting\CursorCodec;
use Karnoweb\Accounting\Reporting\PaginationMeta;
use Karnoweb\Accounting\Reporting\ReportMeta;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Support\Amount;

/**
 * Builds party-level aging from an external open-item provider.
 * Never reads the general ledger for due dates or counterparties.
 */
final class AgingReport
{
    public function __construct(
        private readonly AgingSourceProvider $provider,
        private readonly AgingFilters $filters,
    ) {}

    public function build(): AgingReportResult
    {
        $parties = [];
        foreach ($this->provider->queryOpenItems($this->filters) as $item) {
            if (! $item instanceof AgingOpenItem) {
                continue;
            }

            $outstanding = Amount::of($item->outstandingAmount);
            if ($outstanding->isZero()) {
                continue;
            }

            $partyId = $item->partyId ?? $item->sourceId;
            $parties[$partyId] ??= [
                'party_id' => $partyId,
                'party_code' => '',
                'party_name' => '',
                'current' => Amount::zero(),
                'days_1_30' => Amount::zero(),
                'days_31_60' => Amount::zero(),
                'days_61_90' => Amount::zero(),
                'days_91_120' => Amount::zero(),
                'days_120_plus' => Amount::zero(),
                'total' => Amount::zero(),
                'oldest_due_date' => null,
                'items' => [],
                'branch_id' => $item->branchId,
            ];

            $bucket = $this->bucket($item);
            $parties[$partyId][$bucket] = Amount::of($parties[$partyId][$bucket])->add($outstanding);
            $parties[$partyId]['total'] = Amount::of($parties[$partyId]['total'])->add($outstanding);
            $parties[$partyId]['items'][] = $item;
            if ($parties[$partyId]['oldest_due_date'] === null || $item->dueDate < $parties[$partyId]['oldest_due_date']) {
                $parties[$partyId]['oldest_due_date'] = $item->dueDate;
            }
        }

        $rows = [];
        foreach ($parties as $party) {
            $rows[] = new AgingPartyRow(
                partyId: $party['party_id'],
                partyCode: (string) $party['party_code'],
                partyName: (string) $party['party_name'],
                current: Amount::of($party['current'])->toStorage(),
                days1To30: Amount::of($party['days_1_30'])->toStorage(),
                days31To60: Amount::of($party['days_31_60'])->toStorage(),
                days61To90: Amount::of($party['days_61_90'])->toStorage(),
                days91To120: Amount::of($party['days_91_120'])->toStorage(),
                days120Plus: Amount::of($party['days_120_plus'])->toStorage(),
                totalOutstanding: Amount::of($party['total'])->toStorage(),
                oldestDueDate: $party['oldest_due_date'],
                openItemCount: count($party['items']),
                branchId: $party['branch_id'],
                items: $party['items'],
            );
        }

        usort($rows, fn (AgingPartyRow $a, AgingPartyRow $b) => ((string) $a->partyId) <=> ((string) $b->partyId));
        [$page, $pagination] = $this->paginate($rows);

        $reportTotal = Amount::zero();
        foreach ($rows as $row) {
            $reportTotal = $reportTotal->add($row->totalOutstanding);
        }

        return new AgingReportResult(
            meta: ReportMeta::make('aging_'.$this->filters->side, $this->filters->toArray(), $this->filters->branchScope->toArray()),
            summary: [
                'as_of_date' => $this->filters->asOfDate,
                'side' => $this->filters->side,
                'party_count' => count($rows),
                'total_outstanding' => $reportTotal->toStorage(),
            ],
            data: $page,
            pagination: $pagination,
        );
    }

    private function bucket(AgingOpenItem $item): string
    {
        $days = $item->daysOverdue;
        if ($days <= 0) {
            return 'current';
        }
        if ($days <= 30) {
            return 'days_1_30';
        }
        if ($days <= 60) {
            return 'days_31_60';
        }
        if ($days <= 90) {
            return 'days_61_90';
        }
        if ($days <= 120) {
            return 'days_91_120';
        }

        return 'days_120_plus';
    }

    /**
     * @param  list<AgingPartyRow>  $rows
     * @return array{0: list<AgingPartyRow>, 1: PaginationMeta}
     */
    private function paginate(array $rows): array
    {
        $pagination = $this->filters->pagination;

        if ($pagination->mode === ReportPagination::MODE_OFFSET) {
            $total = count($rows);
            $slice = array_slice($rows, $pagination->offsetValue(), $pagination->perPage);

            return [
                $slice,
                PaginationMeta::offset(
                    $pagination->page,
                    $pagination->perPage,
                    $total,
                    $pagination->offsetValue() + count($slice) < $total,
                ),
            ];
        }

        $start = 0;
        if ($pagination->cursor !== null) {
            $keys = CursorCodec::decode($pagination->cursor);
            $cursorId = (string) ($keys['party_id'] ?? '');
            foreach ($rows as $index => $row) {
                if ((string) $row->partyId === $cursorId) {
                    $start = $index + 1;
                    break;
                }
            }
        }

        $slice = array_slice($rows, $start, $pagination->perPage + 1);
        $hasMore = count($slice) > $pagination->perPage;
        $slice = array_slice($slice, 0, $pagination->perPage);
        $next = $hasMore && $slice !== []
            ? CursorCodec::encode(['party_id' => $slice[array_key_last($slice)]->partyId])
            : null;

        return [$slice, PaginationMeta::cursor($pagination->perPage, $hasMore, $next)];
    }
}
