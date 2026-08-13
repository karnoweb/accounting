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
}
