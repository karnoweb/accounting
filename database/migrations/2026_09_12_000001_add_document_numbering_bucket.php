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

        if (! Schema::hasColumn($table, 'numbering_bucket')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('numbering_bucket')->default(0)->after('number');
            });
        }

        // MySQL error 1553: InnoDB may use unique(fiscal_year_id, number) as the
        // supporting index for documents.fiscal_year_id FK. Dropping that unique
        // is refused unless another index on fiscal_year_id already exists.
        $this->ensureFiscalYearForeignKeyIndex($table, $prefix);

        $newUnique = 'acc_documents_fy_bucket_number_unique';
        if (! $this->indexExists($table, $newUnique)) {
            Schema::table($table, function (Blueprint $blueprint) use ($newUnique) {
                $blueprint->unique(
                    ['fiscal_year_id', 'numbering_bucket', 'number'],
                    $newUnique
                );
            });
        }

        foreach ($this->legacyNumberUniques($prefix) as $oldUnique) {
            if ($this->indexExists($table, $oldUnique)) {
                Schema::table($table, function (Blueprint $blueprint) use ($oldUnique) {
                    $blueprint->dropUnique($oldUnique);
                });
            }
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $prefix.'documents';

        $this->ensureFiscalYearForeignKeyIndex($table, $prefix);

        if ($this->indexExists($table, 'acc_documents_fy_bucket_number_unique')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique('acc_documents_fy_bucket_number_unique');
            });
        }

        $legacy = $prefix.'documents_fiscal_year_id_number_unique';
        if (! $this->indexExists($table, $legacy)) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(['fiscal_year_id', 'number']);
            });
        }

        if (Schema::hasColumn($table, 'numbering_bucket')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('numbering_bucket');
            });
        }
    }

    private function ensureFiscalYearForeignKeyIndex(string $table, string $prefix): void
    {
        if ($this->hasExactColumnIndex($table, ['fiscal_year_id'])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($prefix) {
            $blueprint->index('fiscal_year_id', $prefix.'documents_fiscal_year_id_fk_support');
        });
    }

    /** @return list<string> */
    private function legacyNumberUniques(string $prefix): array
    {
        return [
            $prefix.'documents_fiscal_year_id_number_unique',
            'acc_documents_fiscal_year_id_number_unique',
        ];
    }

    /** @param  list<string>  $columns */
    private function hasExactColumnIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return true;
            }
        }

        return Schema::hasIndex($table, $name);
    }
};
