<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountingPeriodStatus;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Support\Amount;
use Karnoweb\Accounting\Tests\TestCase;

class PeriodClosingReportTest extends TestCase
{
    use AdvancedReportsFixture;

    public function test_branch_readiness_matrix(): void
    {
        $world = $this->turnoverWorld();
        $this->createDraft($world['fy2026'], $world['cash'], $world['revenue'], 20, '2026-01-22', 2);

        $a = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());
        $this->assertTrue($a->isReadyToClose());

        $b = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 2,
        ], $this->offsetPage());
        $this->assertFalse($b->isReadyToClose());
        $codes = array_column($b->readiness['blockers'], 'code');
        $this->assertContains('UNPOSTED_DOCUMENTS', $codes);

        $all = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'all_branches' => true,
            'include_branch_breakdown' => true,
        ], $this->offsetPage());
        $this->assertFalse($all->isReadyToClose());
    }

    public function test_already_closed_period_and_no_mutation(): void
    {
        $world = $this->turnoverWorld();
        $before = Document::query()->count();
        $world['jan']->update([
            'status' => AccountingPeriodStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $first = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());
        $second = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());

        $this->assertFalse($first->isReadyToClose());
        $this->assertContains('PERIOD_ALREADY_CLOSED', array_column($first->readiness['blockers'], 'code'));
        $this->assertSame($before, Document::query()->count());
        $this->assertSame($first->activity['posted_document_count'], $second->activity['posted_document_count']);
    }

    public function test_existing_closing_document_and_missing_retained_earnings(): void
    {
        $world = $this->turnoverWorld();
        $this->postJournal($world['fy2026'], $world['expense'], $world['revenue'], 10, '2026-01-30', branchId: 1, type: 'closing');

        config(['accounting.account.system_accounts.retained_earnings' => '']);

        $result = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());

        $warningCodes = array_column($result->readiness['warnings'], 'code');
        $this->assertContains('EXISTING_CLOSING_JOURNAL', $warningCodes);
        $this->assertContains('RETAINED_EARNINGS_AVAILABLE', $warningCodes);
        $this->assertContains('SYSTEM_ACCOUNTS_AVAILABLE', $warningCodes);
    }

    public function test_reversal_void_decimal_and_temporary_pagination(): void
    {
        $world = $this->turnoverWorld();
        $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], '12.50', '2026-01-24', branchId: 1);
        $original = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 4, '2026-01-24', branchId: 1);
        $original->reverse('x');
        $voided = $this->postJournal($world['fy2026'], $world['cash'], $world['revenue'], 3, '2026-01-24', branchId: 1);
        $voided->void('x');

        $result = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage(1, 1));

        $this->assertTrue(Amount::of($result->activity['total_debit'])->equals($result->activity['total_credit']));
        $this->assertSame(1, $result->activity['reversal_document_count']);
        $this->assertSame(1, $result->activity['voided_document_count']);
        $this->assertGreaterThan(0, $result->temporaryAccounts['temporary_accounts_remaining']);
        $this->assertCount(1, $result->temporaryAccountRows);
        $this->assertTrue($result->pagination->hasMore);
    }

    public function test_period_outside_fiscal_year_is_rejected(): void
    {
        $world = $this->turnoverWorld();

        $this->expectException(\Karnoweb\Accounting\Exceptions\InvalidReportFilterException::class);
        $this->report()->periodClosing([
            'fiscal_year_id' => $world['fy2025']->id,
            'accounting_period_id' => $world['jan']->id,
        ]);
    }

    public function test_opening_incomplete_is_a_warning(): void
    {
        $world = $this->turnoverWorld();
        $world['fy2026']->update(['opening_done' => false]);

        $result = $this->report()->periodClosing([
            'accounting_period_id' => $world['jan']->id,
            'branch_id' => 1,
        ], $this->offsetPage());

        $this->assertContains('OPENING_INCOMPLETE', array_column($result->readiness['warnings'], 'code'));
    }
}
