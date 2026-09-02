<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Support;

/**
 * Single source of truth for "which branch applies when none is given explicitly".
 *
 * Mirrors the resolution order documented for document creation: a callable
 * `accounting.branch.resolver` wins when configured, otherwise
 * `accounting.branch.default_id` is used. Returns null when branching is
 * disabled or neither option yields a value.
 *
 * Used by DocumentService::create() (default branch for new documents) and
 * AccountingManager::currentBranch() so both resolve the same branch — the
 * previous currentBranch() ignored the resolver entirely.
 */
final class BranchContext
{
    public static function resolveDefaultId(): ?int
    {
        if ( ! config('accounting.branch.enabled', true)) {
            return null;
        }

        $resolver = config('accounting.branch.resolver');
        if ($resolver && is_callable($resolver)) {
            $id = $resolver();

            return $id !== null ? (int) $id : null;
        }

        $default = config('accounting.branch.default_id');

        return $default !== null ? (int) $default : null;
    }
}
