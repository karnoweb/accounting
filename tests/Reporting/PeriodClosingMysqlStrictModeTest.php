<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Tests\TestCase;
use PDO;

/**
 * MariaDB/MySQL ONLY_FULL_GROUP_BY regression for periodClosing date bounds.
 *
 * Override connection with ACCOUNTING_MYSQL_HOST / PORT / DATABASE / USERNAME / PASSWORD.
 * Defaults match a local EnvKit MariaDB (127.0.0.1:3306, root, empty password).
 */
class PeriodClosingMysqlStrictModeTest extends TestCase
{
    use AdvancedReportsFixture;

    private const TEST_DATABASE = 'karnoweb_accounting_phpunit';

    private static ?bool $reachable = null;

    protected function setUp(): void
    {
        if (! self::mysqlReachable()) {
            $this->markTestSkipped(
                'MariaDB/MySQL is required for the ONLY_FULL_GROUP_BY periodClosing regression.'
            );
        }

        RefreshDatabaseState::$migrated = false;

        parent::setUp();

        DB::statement("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, IF(@@SESSION.sql_mode LIKE '%ONLY_FULL_GROUP_BY%', '', ',ONLY_FULL_GROUP_BY'))");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$migrated = false;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! self::mysqlReachable()) {
            return;
        }

        $config = self::mysqlConfig();
        $app['config']->set('database.default', 'mysql_strict');
        $app['config']->set('database.connections.mysql_strict', [
            'driver' => 'mysql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
    }

    public function test_period_closing_does_not_mix_star_columns_with_date_aggregates(): void
    {
        $world = $this->turnoverWorld();

        try {
            $sql = $this->firstPostedDateBoundsSql(function () use ($world, &$result) {
                $result = $this->report()->periodClosing([
                    'accounting_period_id' => $world['jan']->id,
                    'branch_id' => 1,
                ], $this->offsetPage());
            });
        } catch (QueryException $exception) {
            $this->fail('periodClosing() raised QueryException on MySQL/MariaDB: '.$exception->getMessage());
        }

        $this->assertNotNull($result);
        $this->assertSame('2026-01-10', $result->activity['first_posted_document_date']);
        $this->assertSame('2026-01-20', $result->activity['last_posted_document_date']);
        $this->assertStringNotContainsString('acc_documents.*', $sql);
        $this->assertStringNotContainsString('`acc_documents`.*', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '/`?acc_documents`?\s*\*.*(?:MIN|MAX)\(|(?:MIN|MAX)\(.*`?acc_documents`?\s*\*/is',
            $sql,
        );
        $this->assertMatchesRegularExpression('/MIN\(.+\.date\).+MAX\(.+\.date\)/is', $sql);
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    private static function mysqlConfig(): array
    {
        return [
            'host' => self::envOr('ACCOUNTING_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) self::envOr('ACCOUNTING_MYSQL_PORT', '3306'),
            'database' => self::envOr('ACCOUNTING_MYSQL_DATABASE', self::TEST_DATABASE),
            'username' => self::envOr('ACCOUNTING_MYSQL_USERNAME', 'root'),
            'password' => self::envOr('ACCOUNTING_MYSQL_PASSWORD', ''),
        ];
    }

    private static function mysqlReachable(): bool
    {
        if (self::$reachable !== null) {
            return self::$reachable;
        }

        if (! extension_loaded('pdo_mysql')) {
            return self::$reachable = false;
        }

        $config = self::mysqlConfig();

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d', $config['host'], $config['port']),
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $database = str_replace('`', '', $config['database']);
            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (\Throwable) {
            return self::$reachable = false;
        }

        return self::$reachable = true;
    }

    private static function envOr(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
