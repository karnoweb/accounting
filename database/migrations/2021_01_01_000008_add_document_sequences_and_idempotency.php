<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::create($prefix . 'document_number_sequences', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained($prefix . 'fiscal_years')->cascadeOnDelete();
            // 0 = package-wide numbering within the fiscal year (when separate_numbering is false).
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'branch_id'], 'acc_doc_num_seq_fy_branch_unique');
        });

        Schema::table($prefix . 'documents', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('source_id');
            $table->unique('idempotency_key', 'acc_documents_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::table($prefix . 'documents', function (Blueprint $table) {
            $table->dropUnique('acc_documents_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists($prefix . 'document_number_sequences');
    }
};
