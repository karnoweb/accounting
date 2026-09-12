<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

final class AccountNode
{
    /**
     * @param  list<AccountNode>  $children
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly int $level,
        public readonly ?int $parentId,
        public readonly bool $isActive,
        public readonly bool $hasChildren,
        public readonly int $childrenCount,
        public readonly ?int $level4Count = null,
        public readonly ?bool $hasLevel4 = null,
        public readonly array $children = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'level' => $this->level,
            'parent_id' => $this->parentId,
            'is_active' => $this->isActive,
            'has_children' => $this->hasChildren,
            'children_count' => $this->childrenCount,
        ];

        if ($this->level4Count !== null) {
            $payload['level_4_count'] = $this->level4Count;
        }

        if ($this->hasLevel4 !== null) {
            $payload['has_level_4'] = $this->hasLevel4;
        }

        if ($this->children !== []) {
            $payload['children'] = array_map(fn (self $child) => $child->toArray(), $this->children);
        }

        return $payload;
    }
}
