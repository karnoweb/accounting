<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade path: every existing fiscal year gets one covering period so posting
 * continues to work after AccountingPeriod becomes a required posting gate.
 *
 * Status mapping: draft→draft, active→open, closed→closed.
 */
return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $fyTable = $prefix . 'fiscal_years';
        $periodTable = $prefix . 'accounting_periods';

        if ( ! Schema::hasTable($fyTable) || ! Schema::hasTable($periodTable)) {
            return;
        }

        $now = now();

        DB::table($fyTable)->orderBy('id')->each(function (object $fy) use ($periodTable, $now) {
            $exists = DB::table($periodTable)
                ->where('fiscal_year_id', $fy->id)
                ->exists();

            if ($exists) {
                return;
            }

            $status = match ((string) $fy->status) {
                'active' => 'open',
                'closed' => 'closed',
                default => 'draft',
            };

            DB::table($periodTable)->insert([
                'fiscal_year_id' => $fy->id,
                'name' => $fy->title,
                'start_date' => $fy->start_date,
                'end_date' => $fy->end_date,
                'status' => $status,
                'opened_at' => $status === 'draft' ? null : ($fy->opened_at ?? $now),
                'closed_at' => $status === 'closed' ? ($fy->closed_at ?? $now) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Seeded rows are left in place; down of the create migration drops the table.
    }
};
