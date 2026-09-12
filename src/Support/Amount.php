<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Canonical decimal amount for accounting arithmetic.
 *
 * Calculations never use PHP floating-point. Values are stored as decimal
 * strings at the package storage scale (`accounting.general.decimal_places`,
 * default 2 — matching `decimal(15,2)` columns).
 *
 * Public service/DTO APIs may still expose `float` for backward compatibility;
 * those values must be produced with {@see toFloat()} only at the boundary.
 */
final class Amount implements Stringable
{
    private function __construct(private readonly string $value) {}

    /**
     * Storage / comparison / presentation scale.
     * Calculation uses a higher working scale, then normalizes here.
     */
    public static function scale(): int
    {
        try {
            return max(0, (int) config('accounting.general.decimal_places', 2));
        } catch (\Throwable) {
            return 2;
        }
    }

    public static function zero(): self
    {
        return new self(self::formatFixed('0', self::scale()));
    }

    public static function of(int|float|string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return new self(self::parse($value));
    }

    public function add(int|float|string|self $other): self
    {
        return new self(self::normalize(bcadd($this->value, self::of($other)->value, self::workingScale())));
    }

    public function subtract(int|float|string|self $other): self
    {
        return new self(self::normalize(bcsub($this->value, self::of($other)->value, self::workingScale())));
    }

    public function multiply(int|string $factor): self
    {
        return new self(self::normalize(bcmul($this->value, (string) $factor, self::workingScale())));
    }

    public function negate(): self
    {
        return $this->multiply(-1);
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    /**
     * Apply journal sign: 1 = debit (unchanged), -1 = credit (negated).
     */
    public function signed(int $sign): self
    {
        if ($sign !== 1 && $sign !== -1) {
            throw new InvalidArgumentException("Invalid sign: {$sign}");
        }

        return $this->multiply($sign);
    }

    public function compare(int|float|string|self $other): int
    {
        return bccomp($this->value, self::of($other)->value, self::scale());
    }

    public function equals(int|float|string|self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->equals(0);
    }

    public function isPositive(): bool
    {
        return $this->compare(0) > 0;
    }

    public function isNegative(): bool
    {
        return $this->compare(0) < 0;
    }

    /**
     * Signed movement debit − credit.
     */
    public static function signedBalance(int|float|string|self $debit, int|float|string|self $credit): self
    {
        return self::of($debit)->subtract($credit);
    }

    /**
     * @param  iterable<int|float|string|self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    /**
     * Normalized decimal string for persistence and SQL literals.
     */
    public function toStorage(): string
    {
        return $this->value;
    }

    /**
     * Compat boundary only. Do not use the result for further accounting math.
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function workingScale(): int
    {
        return max(self::scale() + 4, 8);
    }

    private static function parse(int|float|string $value): string
    {
        if (is_int($value)) {
            return self::normalize((string) $value);
        }

        if (is_float($value)) {
            if ( ! is_finite($value)) {
                throw new InvalidArgumentException('Amount must be a finite number.');
            }

            // Inbound API conversion only — never calculate in float.
            return self::normalize(sprintf('%.' . (self::scale() + 6) . 'F', $value));
        }

        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '+' || $value === '.') {
            throw new InvalidArgumentException('Invalid amount.');
        }

        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        if (preg_match('/[eE]/', $value) === 1) {
            throw new InvalidArgumentException('Amount must be a decimal number, not scientific notation.');
        }

        if ( ! preg_match('/^-?\d*\.?\d+$/', $value)) {
            throw new InvalidArgumentException("Invalid amount: {$value}");
        }

        return self::normalize($value);
    }

    private static function normalize(string $value): string
    {
        return self::roundHalfAwayFromZero($value, self::scale());
    }

    /**
     * Half away from zero — same convention as PHP `round($n, $scale)`.
     */
    private static function roundHalfAwayFromZero(string $number, int $scale): string
    {
        $working = max($scale + 4, 8);
        $negative = str_starts_with($number, '-');
        $abs = $negative ? substr($number, 1) : $number;

        $half = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), $scale + 1);
        $adjusted = bcadd($abs, $half, $working);
        $truncated = bcadd($adjusted, '0', $scale);
        $formatted = self::formatFixed($truncated, $scale);

        if ($negative && bccomp($formatted, '0', $scale) !== 0) {
            return '-' . $formatted;
        }

        return $formatted;
    }

    private static function formatFixed(string $number, int $scale): string
    {
        if (str_starts_with($number, '-')) {
            $number = substr($number, 1);
        }

        if ( ! str_contains($number, '.')) {
            $int = self::normalizeInteger($number);

            return $scale === 0 ? $int : $int . '.' . str_repeat('0', $scale);
        }

        [$int, $frac] = explode('.', $number, 2);
        $int = self::normalizeInteger($int);
        $frac = str_pad($frac, $scale, '0');

        if ($scale === 0) {
            return $int;
        }

        return $int . '.' . substr($frac, 0, $scale);
    }

    private static function normalizeInteger(string $int): string
    {
        $int = ltrim($int, '0');

        return $int === '' ? '0' : $int;
    }
}
