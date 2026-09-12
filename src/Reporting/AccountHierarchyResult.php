<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class AccountHierarchyResult
{
    /**
     * @param  list<AccountNode>  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'data' => array_map(fn (AccountNode $node) => $node->toArray(), $this->data),
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                $ids[] = $node->id;
                $walk($node->children);
            }
        };
        $walk($this->data);

        return $ids;
    }
}
