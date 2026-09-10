<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\FiscalYearStateException;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\OpeningService;

class MultiActiveOpeningPolicyTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    private function opening(): OpeningService
    {
        return app(OpeningService::class);
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function activateYear(string $title, string $start, string $end): FiscalYear
    {
        return $this->years()->activate($this->years()->create([
            'title' => $title,
            'start_date' => $start,
            'end_date' => $end,
        ]));
    }

    public function test_two_active_years_and_set_current(): void
    {
        $fy2025 = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $fy2026 = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        $this->assertTrue($fy2025->fresh()->isActive());
        $this->assertTrue($fy2026->fresh()->isActive());
        $this->assertFalse($fy2025->fresh()->is_current);
        $this->assertTrue($fy2026->fresh()->is_current);
        $this->assertSame($fy2026->id, $this->years()->current()?->id);

        $this->years()->setCurrent($fy2025);
        $this->assertTrue($fy2025->fresh()->is_current);
        $this->assertFalse($fy2026->fresh()->is_current);
        $this->assertSame($fy2025->id, FiscalYear::current()?->id);
    }

    public function test_closing_current_promotes_other_active_year(): void
    {
        $fy2025 = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $fy2026 = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->assertTrue($fy2026->fresh()->is_current);

        $this->years()->close($fy2026);

        $this->assertSame(FiscalYearStatus::CLOSED, $fy2026->fresh()->status);
        $this->assertTrue($fy2025->fresh()->isActive());
        $this->assertTrue($fy2025->fresh()->is_current);
        $this->assertSame($fy2025->id, $this->years()->current()?->id);
    }

    public function test_document_date_resolves_prior_active_year_not_current(): void
    {
        $fy2025 = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $fy2026 = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $this->assertTrue($fy2026->fresh()->is_current);

        $chart = $this->createPostableChart();
        $document = $this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-15',
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 25),
        ]);

        $this->assertSame($fy2025->id, $document->fiscal_year_id);
        $this->assertNotSame($fy2026->id, $document->fiscal_year_id);
    }

    public function test_opening_confirm_allowed_after_operational_when_configured(): void
    {
        config([
            'accounting.opening.allow_after_posted_activity' => true,
            'accounting.opening.require_prior_year_closed_for_confirm' => false,
        ]);

        $fy = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();

        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-03-01',
            'fiscal_year_id' => $fy->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 10),
        ]));

        $this->opening()->saveDraft($fy, $this->balancedItems($chart['detail'], $chart['detail2'], 40));
        $posted = $this->opening()->confirm($fy);

        $this->assertTrue($posted->isPosted());
        $this->assertTrue($fy->fresh()->opening_done);
    }

    public function test_confirm_blocked_while_prior_year_still_active(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');
        $chart = $this->createPostableChart();

        $this->opening()->saveDraft($target, $this->balancedItems($chart['detail'], $chart['detail2'], 50));

        try {
            $this->opening()->confirm($target);
            $this->fail('Confirm must wait for prior year close');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(
                __('accounting::accounting.messages.opening_prior_year_not_closed'),
                $e->getMessage()
            );
            $this->assertFalse($target->fresh()->opening_done);
            $this->assertSame(DocumentStatus::DRAFT, Document::query()->where('type', 'opening')->first()->status);
        }

        $this->assertTrue($source->fresh()->isActive());
    }

    public function test_provisional_carry_forward_then_final_confirm_after_close(): void
    {
        $source = $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $chart = $this->createPostableChart();
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-06-01',
            'fiscal_year_id' => $source->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 120),
        ]));

        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        // Sales in the new year before opening is final.
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2026-01-15',
            'fiscal_year_id' => $target->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 5),
        ]));

        $drafts = $this->opening()->carryForward($source, $target);
        $this->assertCount(1, $drafts);
        $this->assertSame(DocumentStatus::DRAFT, $drafts[0]->status);
        $this->assertTrue($drafts[0]->meta['provisional']);
        $this->assertFalse($target->fresh()->opening_done);

        try {
            $this->opening()->confirm($target);
            $this->fail('Provisional opening must not confirm while prior year is open');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(
                __('accounting::accounting.messages.opening_prior_year_not_closed'),
                $e->getMessage()
            );
        }

        // Adjust prior year, refresh provisional draft.
        $this->documents()->post($this->documents()->create([
            'type' => 'adjustment',
            'date' => '2025-11-01',
            'fiscal_year_id' => $source->id,
            'items' => $this->balancedItems($chart['detail'], $chart['detail2'], 30),
        ]));
        $refreshed = $this->opening()->carryForward($source, $target);
        $this->assertSame($drafts[0]->id, $refreshed[0]->id);
        $debit = $refreshed[0]->items->firstWhere('account_id', $chart['detail']->id);
        $this->assertEqualsWithDelta(150.0, (float) $debit->amount, 0.001);

        $this->years()->close($source);
        $finalDrafts = $this->opening()->carryForward($source->fresh(), $target);
        $this->assertFalse($finalDrafts[0]->meta['provisional']);

        $posted = $this->opening()->confirm($target);
        $this->assertTrue($posted->isPosted());
        $this->assertTrue($target->fresh()->opening_done);
        $this->assertEqualsWithDelta(
            150.0,
            (float) $posted->items->firstWhere('account_id', $chart['detail']->id)->amount,
            0.001
        );
    }

    public function test_complete_opening_flag_also_requires_prior_closed(): void
    {
        $this->activateYear('FY 2025', '2025-01-01', '2025-12-31');
        $target = $this->activateYear('FY 2026', '2026-01-01', '2026-12-31');

        try {
            $this->years()->completeOpening($target);
            $this->fail('completeOpening must respect prior-year gate');
        } catch (FiscalYearStateException $e) {
            $this->assertSame(
                __('accounting::accounting.messages.opening_prior_year_not_closed'),
                $e->getMessage()
            );
            $this->assertFalse($target->fresh()->opening_done);
        }
    }
}
