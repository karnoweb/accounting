<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use Illuminate\Support\Facades\Schema;

class NumberingBucketMigrationTest extends TestCase
{
    public function test_numbering_unique_is_bucket_scoped_and_fiscal_year_fk_has_own_index(): void
    {
        $table = 'acc_documents';

        $this->assertTrue(Schema::hasColumn($table, 'numbering_bucket'));
        $this->assertTrue($this->indexExists($table, 'acc_documents_fy_bucket_number_unique'));
        $this->assertFalse($this->indexExists($table, 'acc_documents_fiscal_year_id_number_unique'));
        $this->assertTrue($this->hasExactColumnIndex($table, ['fiscal_year_id']));
    }

    public function test_numbering_bucket_migration_is_safe_to_rerun(): void
    {
        $migration = require dirname(__DIR__).'/database/migrations/2026_09_12_000001_add_document_numbering_bucket.php';

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('acc_documents', 'numbering_bucket'));
        $this->assertTrue($this->indexExists('acc_documents', 'acc_documents_fy_bucket_number_unique'));
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
}
