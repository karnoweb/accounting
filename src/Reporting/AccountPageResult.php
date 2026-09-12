<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class AccountPageResult
{
    /**
     * @param  list<AccountNode>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly PaginationMeta $pagination,
        public readonly array $meta,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'data' => array_map(fn (AccountNode $node) => $node->toArray(), $this->data),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
