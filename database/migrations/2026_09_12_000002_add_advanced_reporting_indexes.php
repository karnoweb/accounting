<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        // Journal Book / Daily Journal type + posted date range.
        Schema::table($prefix.'documents', function (Blueprint $table) use ($prefix) {
            $table->index(['status', 'type', 'date'], $prefix.'documents_status_type_date_index');
        });

        // Cost-center filtered reports join items back to documents.
        Schema::table($prefix.'document_items', function (Blueprint $table) use ($prefix) {
            $table->index(['cost_center_id', 'document_id'], $prefix.'document_items_cost_center_document_index');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix.'documents', function (Blueprint $table) use ($prefix) {
            $table->dropIndex($prefix.'documents_status_type_date_index');
        });

        Schema::table($prefix.'document_items', function (Blueprint $table) use ($prefix) {
            $table->dropIndex($prefix.'document_items_cost_center_document_index');
        });
    }
};
