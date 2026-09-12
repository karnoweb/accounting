<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $prefix.'accounts';
        $index = 'acc_accounts_level_parent_code_id_index';

        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            // Level-4 listing: WHERE level = ? AND parent_id = ? ORDER BY code, id
            $blueprint->index(['level', 'parent_id', 'code', 'id'], $index);
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $prefix.'accounts';
        $index = 'acc_accounts_level_parent_code_id_index';

        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
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
