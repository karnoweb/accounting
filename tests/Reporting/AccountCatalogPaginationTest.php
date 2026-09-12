<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Reporting\AccountCatalog;
use Karnoweb\Accounting\Reporting\AccountQueryFilters;
use Karnoweb\Accounting\Reporting\ReportPagination;
use Karnoweb\Accounting\Tests\TestCase;

class AccountCatalogPaginationTest extends TestCase
{
    use PostsJournals;

    public function test_each_public_level_can_be_listed(): void
    {
        $chart = $this->seedChart();

        foreach ([1 => $chart['l1'], 2 => $chart['l2'], 3 => $chart['l3']] as $level => $account) {
            $page = Accounting::account()->paginate(['level' => $level], ReportPagination::offset(1, 50));
            $ids = array_map(fn ($node) => $node->id, $page->data);
            $this->assertContains($account->id, $ids);
            $this->assertSame($level, $page->data[0]->level);
        }

        $level4 = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
        ], ReportPagination::offset(1, 50));
        $ids = array_map(fn ($node) => $node->id, $level4->data);
        $this->assertContains($chart['l4a']->id, $ids);
        $this->assertContains($chart['l4b']->id, $ids);
        $this->assertSame(4, $level4->data[0]->level);
    }

    public function test_parent_search_code_active_and_branch_filters(): void
    {
        $chart = $this->seedChart();
        $inactive = Accounting::account()->create([
            'parent_id' => $chart['l3']->id,
            'title' => 'Inactive leaf',
            'type' => AccountType::ASSET,
            'is_active' => false,
        ]);
        $branchTwo = Accounting::account()->create([
            'parent_id' => $chart['l3']->id,
            'title' => 'Branch two leaf',
            'type' => AccountType::ASSET,
            'branch_id' => 2,
        ]);

        $underParent = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
        ], ReportPagination::offset(1, 50));
        $this->assertContains($inactive->id, array_map(fn ($node) => $node->id, $underParent->data));

        $search = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'search' => 'Inactive',
        ], ReportPagination::offset(1, 50));
        $this->assertSame([$inactive->id], array_map(fn ($node) => $node->id, $search->data));

        $exact = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'code' => $chart['l4a']->code,
        ], ReportPagination::offset(1, 50));
        $codes = array_map(fn ($node) => $node->code, $exact->data);
        $this->assertContains($chart['l4a']->id, array_map(fn ($node) => $node->id, $exact->data));
        $this->assertSame([$chart['l4a']->code], array_unique($codes));

        $active = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'is_active' => true,
        ], ReportPagination::offset(1, 50));
        $this->assertNotContains($inactive->id, array_map(fn ($node) => $node->id, $active->data));

        $branch = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'branch_id' => 2,
        ], ReportPagination::offset(1, 50));
        $branchIds = array_map(fn ($node) => $node->id, $branch->data);
        $this->assertContains($branchTwo->id, $branchIds);
        $this->assertContains($chart['l4a']->id, $branchIds);

        $selected = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'branch_ids' => [2],
        ], ReportPagination::offset(1, 50));
        $this->assertContains($branchTwo->id, array_map(fn ($node) => $node->id, $selected->data));

        $all = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'all_branches' => true,
        ], ReportPagination::offset(1, 50));
        $this->assertContains($branchTwo->id, array_map(fn ($node) => $node->id, $all->data));
    }

    public function test_ambiguous_branch_scope_is_rejected(): void
    {
        $this->seedChart();

        $this->expectException(InvalidReportFilterException::class);
        Accounting::account()->paginate([
            'level' => 4,
            'branch_id' => 1,
            'all_branches' => true,
        ]);
    }

    public function test_level_four_cursor_pages_are_disjoint_complete_and_stable(): void
    {
        $chart = $this->seedChart();
        $created = $this->createLevelFour($chart['l3'], 23);
        $expected = Account::query()
            ->where('level', 3)
            ->where('parent_id', $chart['l3']->id)
            ->orderBy('code')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertGreaterThanOrEqual(25, count($expected));

        $seen = [];
        $cursor = null;
        $pages = 0;
        $perPage = 10;
        do {
            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });
            $page = Accounting::account()->paginate([
                'level' => 4,
                'parent_id' => $chart['l3']->id,
                'cursor' => $cursor,
                'per_page' => $perPage,
            ]);
            $this->assertTrue($this->accountSelectHasLimit($sql));

            $ids = array_map(fn ($node) => $node->id, $page->data);
            $this->assertSame([], array_intersect($seen, $ids));
            $seen = array_merge($seen, $ids);
            $codes = array_map(fn ($node) => $node->code, $page->data);
            $sorted = $codes;
            sort($sorted);
            $this->assertSame($sorted, $codes);
            $cursor = $page->pagination->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($expected, $seen);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame(count($expected), count(array_unique($seen)));

        $small = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
        ], ReportPagination::offset(1, 5));
        $this->assertCount(5, $small->data);

        $searchPage = Accounting::account()->paginate([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
            'search' => $created[0]->code,
            'per_page' => 5,
        ]);
        $this->assertNotEmpty($searchPage->data);
        $this->assertSame($created[0]->code, $searchPage->data[0]->code);

        unset($created);
    }

    public function test_listing_sql_uses_limit_for_level_four(): void
    {
        $chart = $this->seedChart();
        $this->createLevelFour($chart['l3'], 12);
        $catalog = new AccountCatalog(AccountQueryFilters::from([
            'level' => 4,
            'parent_id' => $chart['l3']->id,
        ], requireLevel: true));
        $sql = $catalog->listingQuery()->limit(11)->toSql();

        $this->assertMatchesRegularExpression('/limit/i', $sql);
        $this->assertStringContainsString('parent_id', $sql);
    }

    public function test_invalid_level_is_rejected(): void
    {
        $this->expectException(InvalidAccountHierarchyException::class);
        Accounting::account()->paginate(['level' => 5]);
    }

    /** @return array{l1: Account, l2: Account, l3: Account, l4a: Account, l4b: Account} */
    private function seedChart(): array
    {
        $typed = $this->createTypedChart(AccountType::ASSET, '7');

        return [
            'l1' => $typed['group'],
            'l2' => $typed['general'],
            'l3' => $typed['subsidiary'],
            'l4a' => $typed['detail'],
            'l4b' => $typed['detail2'],
        ];
    }

    /** @return list<Account> */
    private function createLevelFour(Account $parent, int $count): array
    {
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $created[] = Accounting::account()->create([
                'parent_id' => $parent->id,
                'title' => 'Leaf extra '.$i,
                'type' => $parent->type,
            ]);
        }

        return $created;
    }

    /** @param  list<string>  $sql */
    private function accountSelectHasLimit(array $sql): bool
    {
        foreach ($sql as $statement) {
            $normalized = strtolower($statement);
            if (str_contains($normalized, 'acc_accounts')
                && str_starts_with(ltrim($normalized), 'select')
                && ! str_contains($normalized, 'count(')
                && preg_match('/limit/i', $statement)
            ) {
                return true;
            }
        }

        return false;
    }
}
