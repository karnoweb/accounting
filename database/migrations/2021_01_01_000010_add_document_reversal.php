<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->unsignedBigInteger('reversed_document_id')->nullable()->after('idempotency_key');
            $table->foreign('reversed_document_id', $prefix . 'documents_reversed_document_id_foreign')
                ->references('id')
                ->on($prefix . 'documents')
                ->restrictOnDelete();
            $table->index('reversed_document_id', $prefix . 'documents_reversed_document_id_index');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->dropForeign(['reversed_document_id']);
            $table->dropIndex($prefix . 'documents_reversed_document_id_index');
            $table->dropColumn('reversed_document_id');
        });
    }
};
