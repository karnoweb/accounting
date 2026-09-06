<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::create($prefix . 'accounting_periods', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('fiscal_year_id')
                ->constrained($prefix . 'fiscal_years')
                ->restrictOnDelete();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['fiscal_year_id', 'status']);
            $table->index(['fiscal_year_id', 'start_date', 'end_date']);
            $table->index('status');
            $table->unique(['fiscal_year_id', 'start_date', 'end_date'], 'acc_periods_fy_range_unique');
        });

        Schema::table($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->foreignId('accounting_period_id')
                ->nullable()
                ->after('fiscal_year_id')
                ->constrained($prefix . 'accounting_periods')
                ->nullOnDelete();
            $table->index('accounting_period_id');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix . 'documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accounting_period_id');
        });

        Schema::dropIfExists($prefix . 'accounting_periods');
    }
};
