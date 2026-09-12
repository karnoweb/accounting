<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Support\Amount;

final class AccountTurnoverRow
{
    /**
     * @param  list<array<string, mixed>>  $branches
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountCode,
        public readonly string $accountName,
        public readonly string $accountType,
        public readonly string $normalBalance,
        public readonly int $level,
        public readonly ?int $parentId,
        public readonly string $openingDebit,
        public readonly string $openingCredit,
        public readonly string $openingBalance,
        public readonly string $periodDebitTurnover,
        public readonly string $periodCreditTurnover,
        public readonly string $netMovement,
        public readonly string $closingDebit,
        public readonly string $closingCredit,
        public readonly string $closingBalance,
        public readonly int $transactionCount,
        public readonly int $documentCount,
        public readonly array $branches = [],
    ) {}

    public function sortValue(string $sortBy): string
    {
        return match ($sortBy) {
            'account_name' => $this->accountName,
            'opening_balance' => $this->openingBalance,
            'debit_turnover' => $this->periodDebitTurnover,
            'credit_turnover' => $this->periodCreditTurnover,
            'closing_balance' => $this->closingBalance,
            default => $this->accountCode,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'account_type' => $this->accountType,
            'normal_balance' => $this->normalBalance,
            'level' => $this->level,
            'parent_id' => $this->parentId,
            'opening_debit' => $this->openingDebit,
            'opening_credit' => $this->openingCredit,
            'opening_balance' => $this->openingBalance,
            'period_debit_turnover' => $this->periodDebitTurnover,
            'period_credit_turnover' => $this->periodCreditTurnover,
            'net_movement' => $this->netMovement,
            'closing_debit' => $this->closingDebit,
            'closing_credit' => $this->closingCredit,
            'closing_balance' => $this->closingBalance,
            'transaction_count' => $this->transactionCount,
            'document_count' => $this->documentCount,
        ];

        if ($this->branches !== []) {
            $payload['branches'] = $this->branches;
        }

        return $payload;
    }

    public static function fromMetrics(
        object $account,
        Amount $openingDebit,
        Amount $openingCredit,
        Amount $periodDebit,
        Amount $periodCredit,
        int $transactionCount,
        int $documentCount,
        array $branches = [],
    ): self {
        $openingBalance = $openingDebit->subtract($openingCredit);
        $closingDebit = $openingDebit->add($periodDebit);
        $closingCredit = $openingCredit->add($periodCredit);
        $closingBalance = $closingDebit->subtract($closingCredit);

        return new self(
            accountId: (int) $account->id,
            accountCode: (string) $account->code,
            accountName: (string) $account->title,
            accountType: $account->type->value ?? (string) $account->type,
            normalBalance: $account->nature->value ?? (string) $account->nature,
            level: (int) $account->level,
            parentId: $account->parent_id !== null ? (int) $account->parent_id : null,
            openingDebit: $openingDebit->toStorage(),
            openingCredit: $openingCredit->toStorage(),
            openingBalance: $openingBalance->toStorage(),
            periodDebitTurnover: $periodDebit->toStorage(),
            periodCreditTurnover: $periodCredit->toStorage(),
            netMovement: $periodDebit->subtract($periodCredit)->toStorage(),
            closingDebit: $closingDebit->toStorage(),
            closingCredit: $closingCredit->toStorage(),
            closingBalance: $closingBalance->toStorage(),
            transactionCount: $transactionCount,
            documentCount: $documentCount,
            branches: $branches,
        );
    }
}
