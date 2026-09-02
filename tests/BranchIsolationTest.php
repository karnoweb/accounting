<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use InvalidArgumentException;
use Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\AccountNotFoundException;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Support\BranchContext;

/**
 * Regression tests for the multi-branch data-isolation bugs: shared system
 * accounts / retained earnings leaking across branches, unscoped parent
 * lookups letting a branch's accounts nest under another branch's tree, and
 * documents posting against another branch's account.
 */
class BranchIsolationTest extends TestCase
{
    private function accounts(): AccountService
    {
        return app(AccountService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function closing(): ClosingService
    {
        return app(ClosingService::class);
    }

    /**
     * @return array{group: Account, general: Account, subsidiary: Account, detail: Account, detail2: Account}
     */
    private function createPostableChartForBranch(int $branchId): array
    {
        $group = $this->accounts()->create([
            'branch_id' => $branchId,
            'title' => 'Group',
            'type' => AccountType::ASSET,
        ]);
        $general = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $group->id,
            'title' => 'General',
            'type' => AccountType::ASSET,
        ]);
        $subsidiary = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $general->id,
            'title' => 'Subsidiary',
            'type' => AccountType::ASSET,
        ]);
        $detail = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $subsidiary->id,
            'title' => 'Detail A',
            'type' => AccountType::ASSET,
        ]);
        $detail2 = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $subsidiary->id,
            'title' => 'Detail B',
            'type' => AccountType::LIABILITY,
        ]);

        return compact('group', 'general', 'subsidiary', 'detail', 'detail2');
    }

    /** @return array{income: Account, retained: Account} */
    private function createDedicatedRetainedEarningsForBranch(int $branchId, string $code): array
    {
        $equityGroup = $this->accounts()->create([
            'branch_id' => $branchId,
            'title' => 'Equity',
            'type' => AccountType::EQUITY,
        ]);
        $equityGeneral = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $equityGroup->id,
            'title' => 'Capital',
            'type' => AccountType::EQUITY,
        ]);
        $equitySubsidiary = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $equityGeneral->id,
            'title' => 'Retained earnings group',
            'type' => AccountType::EQUITY,
        ]);
        $retained = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $equitySubsidiary->id,
            'code' => $code,
            'title' => 'Retained earnings',
            'type' => AccountType::EQUITY,
        ]);

        $incomeGroup = $this->accounts()->create([
            'branch_id' => $branchId,
            'title' => 'Income',
            'type' => AccountType::INCOME,
        ]);
        $incomeGeneral = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $incomeGroup->id,
            'title' => 'Operating income',
            'type' => AccountType::INCOME,
        ]);
        $incomeSubsidiary = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $incomeGeneral->id,
            'title' => 'Sales',
            'type' => AccountType::INCOME,
        ]);
        $income = $this->accounts()->create([
            'branch_id' => $branchId,
            'parent_id' => $incomeSubsidiary->id,
            'title' => 'Sales income',
            'type' => AccountType::INCOME,
        ]);

        return compact('income', 'retained');
    }

    // -- AccountService::create -------------------------------------------------

    public function test_create_rejects_parent_id_from_a_different_branch(): void
    {
        config(['accounting.branch.enabled' => true]);
        $chart1 = $this->createPostableChartForBranch(1);

        $this->expectException(InvalidAccountHierarchyException::class);

        $this->accounts()->create([
            'branch_id' => 4,
            'parent_id' => $chart1['subsidiary']->id,
            'title' => 'Branch 4 bank',
            'type' => AccountType::ASSET,
        ]);
    }

    public function test_create_rejects_parent_code_fallback_across_branches(): void
    {
        config(['accounting.branch.enabled' => true]);
        $chart1 = $this->createPostableChartForBranch(1);
        $sharedCode = $chart1['group']->code;

        // Branch 4 has no account with this code of its own — must fail loudly,
        // not silently attach under branch 1's account of the same code.
        try {
            $this->accounts()->create([
                'branch_id' => 4,
                'parent_code' => $sharedCode,
                'title' => 'Branch 4 child',
                'type' => AccountType::ASSET,
            ]);
            $this->fail('Cross-branch parent_code fallback must be rejected');
        } catch (AccountNotFoundException) {
            $this->assertSame(0, Account::where('title', 'Branch 4 child')->count());
        }
    }

    public function test_create_allows_child_under_a_shared_branchless_parent(): void
    {
        // Parent has no branch (shared chart) — nesting a branch-specific child
        // under it must still be allowed; only two *different, concrete*
        // branches should be rejected.
        $sharedGroup = $this->accounts()->create([
            'title' => 'Shared group',
            'type' => AccountType::ASSET,
        ]);

        $child = $this->accounts()->create([
            'branch_id' => 4,
            'parent_id' => $sharedGroup->id,
            'title' => 'Branch 4 child',
            'type' => AccountType::ASSET,
        ]);

        $this->assertSame(4, $child->branch_id);
        $this->assertSame($sharedGroup->id, $child->parent_id);
    }

    // -- AccountService::search ---------------------------------------------------

    public function test_search_scopes_by_branch_id_and_includes_shared_accounts(): void
    {
        $shared = $this->accounts()->create(['title' => 'Shared cash', 'type' => AccountType::ASSET]);
        $branch1Only = $this->accounts()->create(['branch_id' => 1, 'title' => 'Branch 1 bank', 'type' => AccountType::ASSET]);
        $branch2Only = $this->accounts()->create(['branch_id' => 2, 'title' => 'Branch 2 bank', 'type' => AccountType::ASSET]);

        $forBranch1 = $this->accounts()->search(['branch_id' => 1])->pluck('id')->all();

        $this->assertContains($shared->id, $forBranch1);
        $this->assertContains($branch1Only->id, $forBranch1);
        $this->assertNotContains($branch2Only->id, $forBranch1);

        $sharedOnly = $this->accounts()->search(['branch_id' => null])->pluck('id')->all();
        $this->assertContains($shared->id, $sharedOnly);
        $this->assertNotContains($branch1Only->id, $sharedOnly);
    }

    // -- AccountService::getSystemAccount -----------------------------------------

    public function test_get_system_account_resolves_the_branch_specific_account(): void
    {
        $branch1Cash = $this->accounts()->create(['branch_id' => 1, 'code' => '999901', 'title' => 'Branch 1 cash', 'type' => AccountType::ASSET]);
        $branch2Cash = $this->accounts()->create(['branch_id' => 2, 'code' => '999901', 'title' => 'Branch 2 cash', 'type' => AccountType::ASSET]);
        config(['accounting.account.system_accounts.cash' => '999901']);

        $this->assertSame($branch1Cash->id, $this->accounts()->getSystemAccount('cash', 1)->id);
        $this->assertSame($branch2Cash->id, $this->accounts()->getSystemAccount('cash', 2)->id);
    }

    public function test_get_system_account_falls_back_to_shared_account_for_branch(): void
    {
        $shared = $this->accounts()->create(['code' => '999902', 'title' => 'Shared cash', 'type' => AccountType::ASSET]);
        config(['accounting.account.system_accounts.cash' => '999902']);

        // No dedicated account for branch 5 — must resolve to the shared one, not fail.
        $this->assertSame($shared->id, $this->accounts()->getSystemAccount('cash', 5)->id);
    }

    // -- AccountingManager::currentBranch (via the shared BranchContext resolver) --

    public function test_branch_context_honors_the_resolver_over_default_id(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 999,
            'accounting.branch.resolver' => fn () => 42,
        ]);

        // AccountingManager::currentBranch() and DocumentService's default-branch
        // logic both delegate to this same resolver — this is what previously
        // diverged (currentBranch ignored the resolver and used default_id).
        $this->assertSame(42, BranchContext::resolveDefaultId());
    }

    public function test_branch_context_falls_back_to_default_id_without_a_resolver(): void
    {
        config([
            'accounting.branch.enabled' => true,
            'accounting.branch.default_id' => 7,
            'accounting.branch.resolver' => null,
        ]);

        $this->assertSame(7, BranchContext::resolveDefaultId());
    }

    public function test_branch_context_is_null_when_branching_disabled(): void
    {
        config([
            'accounting.branch.enabled' => false,
            'accounting.branch.default_id' => 7,
            'accounting.branch.resolver' => fn () => 42,
        ]);

        $this->assertNull(BranchContext::resolveDefaultId());
    }

    // -- DocumentService cross-branch guard ----------------------------------------

    public function test_document_creation_rejects_an_account_from_another_branch(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart1 = $this->createPostableChartForBranch(1);
        $chart2 = $this->createPostableChartForBranch(2);

        try {
            $this->documents()->create([
                'type' => 'adjustment',
                'date' => '2025-06-01',
                'fiscal_year_id' => $fy->id,
                'branch_id' => 1,
                'items' => $this->balancedItems($chart1['detail'], $chart2['detail2'], 10),
            ]);
            $this->fail('Cross-branch item must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_document_creation_allows_shared_accounts_regardless_of_branch(): void
    {
        $fy = $this->createActiveFiscalYear();
        $chart = $this->createPostableChart(); // branch_id null on every account

        $document = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 4,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]);

        $this->assertSame(4, $document->branch_id);
    }

    // -- ClosingService per-branch retained earnings --------------------------------

    public function test_closing_credits_each_branchs_own_retained_earnings_account(): void
    {
        $fy = $this->createActiveFiscalYear();
        config(['accounting.account.system_accounts.retained_earnings' => '999999']);

        $chart1 = $this->createPostableChartForBranch(1);
        $re1 = $this->createDedicatedRetainedEarningsForBranch(1, '999999');
        $chart2 = $this->createPostableChartForBranch(2);
        $re2 = $this->createDedicatedRetainedEarningsForBranch(2, '999999');

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 1,
            'items' => $this->balancedItems($chart1['detail'], $re1['income'], 30),
        ]));
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $fy->id,
            'branch_id' => 2,
            'items' => $this->balancedItems($chart2['detail'], $re2['income'], 50),
        ]));

        $documents = collect($this->closing()->closeProfitAndLoss($fy))
            ->keyBy(fn (Document $document) => (int) $document->branch_id);

        $this->assertCount(2, $documents);

        $branch1Re = $documents[1]->items->firstWhere('account_id', $re1['retained']->id);
        $branch2Re = $documents[2]->items->firstWhere('account_id', $re2['retained']->id);

        $this->assertNotNull($branch1Re, 'Branch 1 closing must credit branch 1 retained earnings');
        $this->assertNotNull($branch2Re, 'Branch 2 closing must credit branch 2 retained earnings');
        $this->assertEqualsWithDelta(30.0, (float) $branch1Re->amount, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $branch2Re->amount, 0.001);

        // Neither branch's document wrote to the other branch's retained earnings account.
        $this->assertNull($documents[1]->items->firstWhere('account_id', $re2['retained']->id));
        $this->assertNull($documents[2]->items->firstWhere('account_id', $re1['retained']->id));
    }

    // -- DefaultAccountsSeeder guard --------------------------------------------------

    public function test_seeder_rejects_incompatible_code_length(): void
    {
        config(['accounting.account.code_length' => [1, 2, 4, 10]]);

        $this->expectException(InvalidArgumentException::class);
        (new DefaultAccountsSeeder)->run();
    }

    public function test_seeder_runs_with_default_code_length(): void
    {
        (new DefaultAccountsSeeder)->run();

        $this->assertTrue(Account::where('code', '310101')->exists());
    }
}
