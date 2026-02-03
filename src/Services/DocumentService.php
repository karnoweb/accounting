<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\ClosedFiscalYearException;
use Karnoweb\Accounting\Exceptions\InactiveAccountException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentItem;
use Karnoweb\Accounting\Models\FiscalYear;

class DocumentService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService
    ) {}

    public function create(array $data): Document
    {
        return DB::transaction(function () use ($data) {
            $fiscalYear = $this->resolveFiscalYear($data);
            $this->validateFiscalYear($fiscalYear, $data['date']);
            $this->validateItems($data['items'] ?? []);

            $number = $data['number'] ?? $this->getNextNumber($fiscalYear, $data['branch_id'] ?? null);

            $document = Document::create([
                'fiscal_year_id' => $fiscalYear->id,
                'branch_id' => $data['branch_id'] ?? $this->getDefaultBranchId(),
                'number' => $number,
                'reference' => $data['reference'] ?? null,
                'date' => $data['date'],
                'type' => $data['type'],
                'status' => $data['status'] ?? DocumentStatus::DRAFT,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'created_by' => auth()->id(),
                'meta' => $data['meta'] ?? null,
            ]);

            $this->createItems($document, $data['items']);

            return $document->load('items.account');
        });
    }

    public function post(Document|int $document): Document
    {
        $document = $document instanceof Document ? $document : Document::with('items')->findOrFail($document);

        if ( ! $document->status->canPost()) {
            throw new Exception(__('accounting::accounting.messages.document_cannot_post'));
        }

        if ( ! $this->isBalanced($document)) {
            throw new UnbalancedDocumentException(
                $document->debit_total,
                $document->credit_total
            );
        }

        $this->validateFiscalYear($document->fiscalYear, $document->date->format('Y-m-d'));

        return DB::transaction(function () use ($document) {
            $document->update([
                'status' => DocumentStatus::POSTED,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $document;
        });
    }

    public function getNextNumber(?FiscalYear $fiscalYear = null, ?int $branchId = null): int
    {
        $fiscalYear ??= FiscalYear::current();

        if ( ! $fiscalYear) {
            throw new RuntimeException(__('accounting::accounting.messages.no_active_fiscal_year'));
        }

        $query = Document::where('fiscal_year_id', $fiscalYear->id);

        if (config('accounting.branch.separate_numbering', false) && $branchId) {
            $query->where('branch_id', $branchId);
        }

        $lastNumber = $query->max('number') ?? 0;

        return $lastNumber + 1;
    }

    public function isBalanced(Document $document): bool
    {
        $balance = $document->items->sum(fn ($item) => $item->amount * $item->sign);

        return abs($balance) < 0.01;
    }

    private function validateItems(array $items): void
    {
        $minItems = config('accounting.document.min_items', 2);

        if (count($items) < $minItems) {
            throw new InvalidArgumentException(__('accounting::accounting.validation.items_required', ['min' => $minItems]));
        }

        foreach ($items as $item) {
            $account = Account::find($item['account_id'] ?? null);

            if ( ! $account) {
                throw new InvalidArgumentException(__('accounting::accounting.validation.account_invalid'));
            }

            if ( ! $account->is_active && config('accounting.validation.check_account_active', true)) {
                throw new InactiveAccountException($account);
            }
        }

        $balance = 0;
        foreach ($items as $item) {
            $balance += ($item['amount'] ?? 0) * ($item['sign'] ?? 1);
        }

        if (config('accounting.validation.strict_balance', true) && abs($balance) >= 0.01) {
            $debit = collect($items)->where('sign', 1)->sum('amount');
            $credit = collect($items)->where('sign', -1)->sum('amount');

            throw new UnbalancedDocumentException($debit, $credit);
        }
    }

    private function createItems(Document $document, array $items): void
    {
        foreach ($items as $index => $item) {
            DocumentItem::create([
                'document_id' => $document->id,
                'account_id' => $item['account_id'],
                'cost_center_id' => $item['cost_center_id'] ?? null,
                'amount' => $item['amount'],
                'sign' => $item['sign'],
                'description' => $item['description'] ?? null,
                'order' => $item['order'] ?? $index,
                'meta' => $item['meta'] ?? null,
            ]);
        }
    }

    private function resolveFiscalYear(array $data): FiscalYear
    {
        if ( ! empty($data['fiscal_year_id'])) {
            return FiscalYear::findOrFail($data['fiscal_year_id']);
        }

        if (config('accounting.fiscal_year.auto_detect', true) && ! empty($data['date'])) {
            $fiscalYear = FiscalYear::findByDate($data['date']);
            if ($fiscalYear) {
                return $fiscalYear;
            }
        }

        $current = FiscalYear::current();
        if ($current) {
            return $current;
        }

        throw new RuntimeException(__('accounting::accounting.messages.no_active_fiscal_year'));
    }

    private function validateFiscalYear(FiscalYear $fiscalYear, string $date): void
    {
        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        if ( ! $fiscalYear->isActive()) {
            throw new RuntimeException(__('accounting::accounting.messages.fiscal_year_not_active'));
        }

        if (config('accounting.validation.check_date_range', true) && ! $fiscalYear->containsDate($date)) {
            throw new RuntimeException(__('accounting::accounting.validation.date_out_of_fiscal_year'));
        }
    }

    private function getDefaultBranchId(): ?int
    {
        if ( ! config('accounting.branch.enabled', true)) {
            return null;
        }

        $resolver = config('accounting.branch.resolver');
        if ($resolver && is_callable($resolver)) {
            return $resolver();
        }

        return config('accounting.branch.default_id');
    }
}
