<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Database\Seeders\DefaultAccountsSeeder;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Services\AccountService;

/**
 * Coverage for the extended default chart of accounts: real-world coverage
 * gaps found across customer apps (HR payroll/loans, karnoweb/laravel-inventory,
 * e-commerce/LMS billing) and the receivables/payables postability fix.
 */
class DefaultAccountsSeederTest extends TestCase
{
    private function accounts(): AccountService
    {
        return app(AccountService::class);
    }

    /** @return array<string, string> system_account key => expected account code */
    private function expectedSystemAccounts(): array
    {
        return [
            'cash' => '110101',
            'bank' => '110201',
            'receivables' => '110300',
            'payables' => '210101',
            'sales_income' => '410101',
            'cost_of_goods' => '510101',
            'refund_expense' => '520101',
            'retained_earnings' => '310101',
            'inventory' => '110901',
            'inventory_shrinkage' => '520401',
            'inventory_count_gain' => '410201',
            'employee_loan_receivable' => '111101',
            'gateway_clearing' => '110501',
            'sales_discount' => '490101',
            'sales_return' => '490201',
            'vat_payable' => '210401',
            'payroll_tax_payable' => '210402',
            'payroll_payable' => '210501',
            'payroll_insurance_payable' => '210502',
            'payroll_salary_expense' => '520201',
            'payroll_employer_insurance' => '520202',
            'bank_fee' => '520301',
        ];
    }

    public function test_seeder_creates_every_configured_system_account(): void
    {
        (new DefaultAccountsSeeder)->run();

        foreach ($this->expectedSystemAccounts() as $key => $code) {
            $configuredCode = config("accounting.account.system_accounts.{$key}");
            $this->assertSame($code, $configuredCode, "config system_accounts.{$key} should map to {$code}");
            $this->assertTrue(
                Account::where('code', $code)->exists(),
                "Seeder should create account {$code} for system_accounts.{$key}"
            );
        }
    }

    public function test_seeder_makes_every_system_account_postable(): void
    {
        (new DefaultAccountsSeeder)->run();

        foreach ($this->expectedSystemAccounts() as $key => $code) {
            $account = Account::where('code', $code)->firstOrFail();

            // Every system account must be safe to pass straight into a
            // document item without AccountService::assertPostable() throwing.
            $this->accounts()->assertPostable($account);
            $this->assertTrue($account->allow_direct_posting, "{$key} ({$code}) must allow direct posting");
        }
    }

    public function test_receivables_and_payables_no_longer_resolve_to_a_non_postable_group(): void
    {
        (new DefaultAccountsSeeder)->run();

        // Regression: these used to resolve to the level-2 group accounts
        // ('1103' / '2101'), which are never postable — any attempt to post a
        // document against systemAccount('receivables'|'payables') threw
        // NotPostableException.
        $receivables = $this->accounts()->getSystemAccount('receivables');
        $payables = $this->accounts()->getSystemAccount('payables');

        $this->assertNotSame('1103', $receivables->code);
        $this->assertNotSame('2101', $payables->code);
        $this->accounts()->assertPostable($receivables);
        $this->accounts()->assertPostable($payables);

        // The old group accounts still exist (for reporting/rollup) and are
        // still, correctly, non-postable groups.
        $this->assertFalse(Account::where('code', '1103')->firstOrFail()->allow_direct_posting);
        $this->assertFalse(Account::where('code', '2101')->firstOrFail()->allow_direct_posting);
    }

    public function test_customer_wallet_liability_group_is_seeded_without_a_system_account_key(): void
    {
        (new DefaultAccountsSeeder)->run();

        $wallet = Account::where('code', '2106')->firstOrFail();

        $this->assertSame('liability', $wallet->type->value);
        // Intentionally a group, not postable — individual wallets get their
        // own detail account nested under it at runtime.
        $this->assertFalse($wallet->allow_direct_posting);
        $this->assertNull(config('accounting.account.system_accounts.wallet'));
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $seeder = new DefaultAccountsSeeder;
        $seeder->run();
        $countAfterFirstRun = Account::count();

        $seeder->run();

        $this->assertSame($countAfterFirstRun, Account::count());
    }
}
