<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Support;

final class AccountHierarchy
{
    public static function maxLevel(): int
    {
        $configured = config('accounting.account.max_level');

        if ($configured !== null) {
            return (int) $configured;
        }

        $lengths = config('accounting.account.code_length', [1, 2, 4, 6]);

        return max(0, count($lengths) - 1);
    }

    public static function postingLevel(): int
    {
        $configured = config('accounting.account.posting_level');

        if ($configured !== null) {
            return (int) $configured;
        }

        return self::maxLevel();
    }

    /**
     * Public/API level is 1-based. Stored `accounts.level` stays 0-based
     * (0 = Level 1, posting = Level 4 with the default four-level chart).
     */
    public static function displayLevel(int $storedLevel): int
    {
        return $storedLevel + 1;
    }

    public static function storedLevel(int $displayLevel): int
    {
        return $displayLevel - 1;
    }

    public static function displayMaxLevel(): int
    {
        return self::maxLevel() + 1;
    }

    /**
     * Hierarchy trees never include the posting level (public Level 4).
     */
    public static function hierarchyMaxDisplayLevel(): int
    {
        return max(1, self::displayLevel(self::postingLevel()) - 1);
    }
}
