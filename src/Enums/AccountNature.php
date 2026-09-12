<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Enums;

use InvalidArgumentException;
use Karnoweb\Accounting\Support\Amount;

enum AccountNature: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::DEBIT => __('accounting::accounting.account_natures.debit'),
            self::CREDIT => __('accounting::accounting.account_natures.credit'),
        };
    }

    public function sign(): int
    {
        return match ($this) {
            self::DEBIT => 1,
            self::CREDIT => -1,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::DEBIT => self::CREDIT,
            self::CREDIT => self::DEBIT,
        };
    }

    public static function fromSign(int $sign): self
    {
        return match ($sign) {
            1 => self::DEBIT,
            -1 => self::CREDIT,
            default => throw new InvalidArgumentException("Invalid sign: {$sign}"),
        };
    }

    /**
     * Nature-aware signed movement: for a DEBIT-nature account (asset, expense),
     * debit increases it; for a CREDIT-nature account (income, liability, equity),
     * credit increases it. Never use abs($balance) as a substitute — e.g. a debit
     * sales return on an income account must reduce that income, not increase it.
     */
    public function naturalAmount(int|float|string $debit, int|float|string $credit): float
    {
        $signed = Amount::signedBalance($debit, $credit);

        return match ($this) {
            self::DEBIT => $signed->toFloat(),
            self::CREDIT => $signed->negate()->toFloat(),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
