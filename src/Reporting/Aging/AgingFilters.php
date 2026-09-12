<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

use Carbon\Carbon;
use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Reporting\BranchScope;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Support\Amount;

/**
 * Filter contract for an external AR/AP open-item provider.
 * The general ledger does not carry due dates or counterparties.
 */
final class AgingFilters
{
    /**
     * @param  list<int>  $partyIds
     * @param  list<int>  $accountIds
     * @param  list<int>  $costCenterIds
     * @param  list<string>  $buckets
     */
    private function __construct(
        public readonly string $asOfDate,
        public readonly string $side,
        public readonly BranchScope $branchScope,
        public readonly array $partyIds,
        public readonly ?string $partyType,
        public readonly array $accountIds,
        public readonly array $costCenterIds,
        public readonly ?string $minOutstanding,
        public readonly bool $overdueOnly,
        public readonly array $buckets,
        public readonly ?string $search,
        public readonly ReportPagination $pagination,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, string $side = 'receivable'): self
    {
        if (! in_array($side, ['receivable', 'payable'], true)) {
            throw new InvalidReportFilterException('Aging side must be receivable or payable.');
        }

        $asOf = $input['as_of_date'] ?? $input['as_of'] ?? Carbon::now()->toDateString();
        try {
            $asOfDate = Carbon::parse($asOf)->toDateString();
        } catch (\Throwable) {
            throw new InvalidReportFilterException('as_of_date is not a valid date.');
        }

        $min = $input['min_outstanding'] ?? $input['min_amount'] ?? null;
        $minOutstanding = $min === null || $min === '' ? null : Amount::of($min)->toStorage();

        $buckets = [];
        if (isset($input['aging_bucket']) && $input['aging_bucket'] !== '') {
            $buckets[] = (string) $input['aging_bucket'];
        }
        if (isset($input['aging_buckets']) && is_iterable($input['aging_buckets'])) {
            foreach ($input['aging_buckets'] as $bucket) {
                $buckets[] = (string) $bucket;
            }
        }

        return new self(
            asOfDate: $asOfDate,
            side: $side,
            branchScope: BranchScope::fromInput($input),
            partyIds: self::ids($input['party_ids'] ?? null, $input['party_id'] ?? null),
            partyType: isset($input['party_type']) ? (string) $input['party_type'] : null,
            accountIds: self::ids($input['account_ids'] ?? null, $input['account_id'] ?? null),
            costCenterIds: self::ids($input['cost_center_ids'] ?? null, $input['cost_center_id'] ?? null),
            minOutstanding: $minOutstanding,
            overdueOnly: filter_var($input['overdue_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            buckets: array_values(array_unique($buckets)),
            search: isset($input['search']) && trim((string) $input['search']) !== '' ? trim((string) $input['search']) : null,
            pagination: ReportPagination::fromInput($input),
        );
    }

    /** @return list<int> */
    private static function ids(mixed $many, mixed $one): array
    {
        $ids = [];
        if (is_iterable($many)) {
            foreach ($many as $id) {
                $ids[] = (int) $id;
            }
        }
        if ($one !== null && $one !== '') {
            $ids[] = (int) $one;
        }

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_of_date' => $this->asOfDate,
            'side' => $this->side,
            'branch_id' => $this->branchScope->branchId,
            'branch_ids' => $this->branchScope->branchIds,
            'all_branches' => $this->branchScope->mode === BranchScope::MODE_ALL,
            'party_ids' => $this->partyIds,
            'party_type' => $this->partyType,
            'account_ids' => $this->accountIds,
            'cost_center_ids' => $this->costCenterIds,
            'min_outstanding' => $this->minOutstanding,
            'overdue_only' => $this->overdueOnly,
            'aging_buckets' => $this->buckets,
            'search' => $this->search,
        ];
    }
}
