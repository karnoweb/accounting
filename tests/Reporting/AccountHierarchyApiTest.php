<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests\Reporting;

use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Exceptions\InvalidAccountHierarchyException;
use Karnoweb\Accounting\Facades\Accounting;
use Karnoweb\Accounting\Support\AccountHierarchy;
use Karnoweb\Accounting\Tests\TestCase;

class AccountHierarchyApiTest extends TestCase
{
    use PostsJournals;

    public function test_default_max_level_is_three_and_excludes_level_four(): void
    {
        $chart = $this->seedChart();
        $result = Accounting::account()->hierarchy();

        $this->assertSame(3, $result->meta['max_level']);
        $this->assertFalse($result->meta['includes_level_4']);
        $this->assertSame(1, $result->data[0]->level);
        $this->assertNotContains($chart['l4a']->id, $result->ids());
        $this->assertNotContains($chart['l4b']->id, $result->ids());
        $this->assertContains($chart['l1']->id, $result->ids());
        $this->assertContains($chart['l2']->id, $result->ids());
        $this->assertContains($chart['l3']->id, $result->ids());
    }

    public function test_max_level_one_two_and_three(): void
    {
        $chart = $this->seedChart();

        $one = Accounting::account()->hierarchy(['max_level' => 1]);
        $this->assertSame([$chart['l1']->id], $one->ids());
        $this->assertSame(1, $one->data[0]->level);
        $this->assertTrue($one->data[0]->hasChildren);

        $two = Accounting::account()->hierarchy(['max_level' => 2]);
        $this->assertContains($chart['l1']->id, $two->ids());
        $this->assertContains($chart['l2']->id, $two->ids());
        $this->assertNotContains($chart['l3']->id, $two->ids());

        $three = Accounting::account()->hierarchy(['max_level' => 3]);
        $l3 = $this->findNode($three->data, $chart['l3']->id);
        $this->assertNotNull($l3);
        $this->assertSame(2, $l3->level4Count);
        $this->assertTrue($l3->hasLevel4);
        $this->assertSame([], $l3->children);
    }

    public function test_max_level_four_is_rejected(): void
    {
        $this->seedChart();

        $this->expectException(InvalidAccountHierarchyException::class);
        Accounting::account()->hierarchy(['max_level' => 4]);
    }

    public function test_hierarchy_does_not_select_posting_rows(): void
    {
        $this->seedChart();
        $sql = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        Accounting::account()->hierarchy(['max_level' => 3]);

        $treeSelects = array_values(array_filter($sql, function (string $statement): bool {
            $normalized = strtolower($statement);

            return str_contains($normalized, 'acc_accounts')
                && str_starts_with(ltrim($normalized), 'select')
                && ! str_contains($normalized, 'count(');
        }));

        $this->assertNotEmpty($treeSelects);
        foreach ($treeSelects as $statement) {
            $this->assertDoesNotMatchRegularExpression('/[`"]?level[`"]?\s*=\s*3/', $statement);
        }
        $this->assertSame(AccountHierarchy::postingLevel(), 3);
    }

    /** @return array{l1: mixed, l2: mixed, l3: mixed, l4a: mixed, l4b: mixed} */
    private function seedChart(): array
    {
        $typed = $this->createTypedChart(AccountType::ASSET, '8');

        return [
            'l1' => $typed['group'],
            'l2' => $typed['general'],
            'l3' => $typed['subsidiary'],
            'l4a' => $typed['detail'],
            'l4b' => $typed['detail2'],
        ];
    }

    /** @param  list<\Karnoweb\Accounting\Reporting\AccountNode>  $nodes */
    private function findNode(array $nodes, int $id): ?\Karnoweb\Accounting\Reporting\AccountNode
    {
        foreach ($nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
            $found = $this->findNode($node->children, $id);
            if ($found) {
                return $found;
            }
        }

        return null;
    }
}
