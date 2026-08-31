<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        // Every reporting query filters on status = 'posted' plus a date range,
        // optionally narrowed by fiscal year and/or branch — see LedgerQuery.
        Schema::table($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->index(['status', 'fiscal_year_id', 'date'], $prefix . 'documents_status_fy_date_index');
            $table->index(['status', 'date'], $prefix . 'documents_status_date_index');
            $table->index(['branch_id', 'status', 'date'], $prefix . 'documents_branch_status_date_index');
        });

        // LedgerQuery filters items by account_id before joining back to documents.
        Schema::table($prefix . 'document_items', function (Blueprint $table) use ($prefix) {
            $table->index(['account_id', 'document_id'], $prefix . 'document_items_account_document_index');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->dropIndex($prefix . 'documents_status_fy_date_index');
            $table->dropIndex($prefix . 'documents_status_date_index');
            $table->dropIndex($prefix . 'documents_branch_status_date_index');
        });

        Schema::table($prefix . 'document_items', function (Blueprint $table) use ($prefix) {
            $table->dropIndex($prefix . 'document_items_account_document_index');
        });
    }
};
