<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::ASSET => __('accounting::accounting.account_types.asset'),
            self::LIABILITY => __('accounting::accounting.account_types.liability'),
            self::EQUITY => __('accounting::accounting.account_types.equity'),
            self::INCOME => __('accounting::accounting.account_types.income'),
            self::EXPENSE => __('accounting::accounting.account_types.expense'),
        };
    }

    public function defaultNature(): AccountNature
    {
        return match ($this) {
            self::ASSET, self::EXPENSE => AccountNature::DEBIT,
            self::LIABILITY, self::EQUITY, self::INCOME => AccountNature::CREDIT,
        };
    }

    public function isPermanent(): bool
    {
        return in_array($this, [
            self::ASSET,
            self::LIABILITY,
            self::EQUITY,
        ], true);
    }

    public function isTemporary(): bool
    {
        return in_array($this, [
            self::INCOME,
            self::EXPENSE,
        ], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
