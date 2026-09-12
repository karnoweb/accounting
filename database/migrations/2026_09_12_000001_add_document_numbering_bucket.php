<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $prefix.'documents';

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('numbering_bucket')->default(0)->after('number');
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropUnique(['fiscal_year_id', 'number']);
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(
                ['fiscal_year_id', 'numbering_bucket', 'number'],
                'acc_documents_fy_bucket_number_unique'
            );
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $prefix.'documents';

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropUnique('acc_documents_fy_bucket_number_unique');
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(['fiscal_year_id', 'number']);
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('numbering_bucket');
        });
    }
};
