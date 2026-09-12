<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;

/**
 * Canonical, mutually exclusive branch selection for reports.
 *
 * Modes:
 * - default: no explicit branch filter (preserves LedgerQuery's unscoped contract)
 * - single: one branch_id
 * - selected: an explicit branch_ids[] list
 * - all: all_branches = true — every branch inside the caller's accounting scope
 *
 * `all_branches` is not "every row in the database across tenants". This package
 * has no company/tenant table; the host must already constrain the connection
 * or query scope. Authorization is delegated to the host application.
 *
 * `branch_id = null` is not converted into all-branches.
 */
final class BranchScope
{
    public const MODE_DEFAULT = 'default';

    public const MODE_SINGLE = 'single';

    public const MODE_SELECTED = 'selected';

    public const MODE_ALL = 'all';

    /**
     * @param  list<int>  $branchIds
     */
    private function __construct(
        public readonly string $mode,
        public readonly ?int $branchId,
        public readonly array $branchIds,
        public readonly bool $includeBreakdown,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $hasId = array_key_exists('branch_id', $input) && $input['branch_id'] !== null && $input['branch_id'] !== '';
        $rawIds = $input['branch_ids'] ?? null;
        $hasIds = is_array($rawIds) && $rawIds !== [];
        $all = filter_var($input['all_branches'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $explicit = (int) $hasId + (int) $hasIds + (int) $all;
        if ($explicit > 1) {
            throw new InvalidReportFilterException(
                'Ambiguous branch scope: use exactly one of branch_id, branch_ids, or all_branches.'
            );
        }

        $breakdown = filter_var($input['include_branch_breakdown'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($hasId) {
            $id = self::normalizeId($input['branch_id']);
            self::assertHostBranchesExist([$id]);

            return new self(self::MODE_SINGLE, $id, [$id], $breakdown);
        }

        if ($hasIds) {
            $ids = [];
            foreach ($rawIds as $raw) {
                $ids[] = self::normalizeId($raw);
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                throw new InvalidReportFilterException('branch_ids must contain at least one branch id.');
            }
            self::assertHostBranchesExist($ids);

            return new self(self::MODE_SELECTED, null, $ids, $breakdown);
        }

        if ($all) {
            return new self(self::MODE_ALL, null, [], $breakdown);
        }

        return new self(self::MODE_DEFAULT, null, [], $breakdown);
    }

    public function apply(LedgerQuery $query): LedgerQuery
    {
        return match ($this->mode) {
            self::MODE_SINGLE => $query->branch($this->branchId),
            self::MODE_SELECTED => $query->branches($this->branchIds),
            self::MODE_ALL => $query->allBranches(),
            default => $query,
        };
    }

    public function isConstrained(): bool
    {
        return in_array($this->mode, [self::MODE_SINGLE, self::MODE_SELECTED], true);
    }

    public function isMulti(): bool
    {
        return in_array($this->mode, [self::MODE_SELECTED, self::MODE_ALL], true)
            || ($this->mode === self::MODE_DEFAULT);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'branch_id' => $this->branchId,
            'branch_ids' => $this->branchIds,
            'all_branches' => $this->mode === self::MODE_ALL,
            'include_branch_breakdown' => $this->includeBreakdown,
        ];
    }

    private static function normalizeId(mixed $id): int
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            throw new InvalidReportFilterException('Branch id must be a positive integer.');
        }

        return (int) $id;
    }

    /** @param  list<int>  $ids */
    private static function assertHostBranchesExist(array $ids): void
    {
        if (! config('accounting.branch.enabled', true)) {
            return;
        }

        $modelClass = config('accounting.branch.model');
        if (! is_string($modelClass) || $modelClass === '' || ! class_exists($modelClass)) {
            return;
        }

        $found = $modelClass::query()->whereIn((new $modelClass)->getKeyName(), $ids)->pluck((new $modelClass)->getKeyName());
        $missing = array_values(array_diff($ids, $found->map(fn ($id) => (int) $id)->all()));
        if ($missing !== []) {
            throw new InvalidReportFilterException(
                'Unknown branch id(s): '.implode(', ', $missing).'.'
            );
        }
    }
}
