<?php

declare(strict_types=1);

namespace Karnoweb\Accounting;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentBuilder;
use Karnoweb\Accounting\Services\DocumentService;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\ReportService;

/**
 * Central manager for accounting services and context.
 *
 * Resolves documents, accounts, balances, reports, fiscal years, and branch/user context.
 */
class AccountingManager
{
    public function __construct(
        protected DocumentService $documentService,
        protected AccountService $accountService,
        protected BalanceService $balanceService,
        protected ReportService $reportService,
        protected FiscalYearService $fiscalYearService,
        protected OpeningService $openingService,
        protected ClosingService $closingService
    ) {}

    /**
     * Fresh document builder each call — state is never shared across constructions.
     */
    public function document(): DocumentBuilder
    {
        return new DocumentBuilder($this->documentService, $this->accountService);
    }

    /** Get the account service for CRUD and lookup of chart-of-accounts. */
    public function account(): AccountService
    {
        return $this->accountService;
    }

    /** Get the balance service for account balances and turnover. */
    public function balance(): BalanceService
    {
        return $this->balanceService;
    }

    /** Get the report service for trial balance and other reports. */
    public function report(): ReportService
    {
        return $this->reportService;
    }

    /** Get the fiscal year service for resolving current or date-based fiscal year and running lifecycle transitions. */
    public function fiscalYear(): FiscalYearService
    {
        return $this->fiscalYearService;
    }

    /** Manual opening journals and carry-forward (type=opening). */
    public function opening(): OpeningService
    {
        return $this->openingService;
    }

    /** P&L close journals (type=closing) into retained earnings. */
    public function closing(): ClosingService
    {
        return $this->closingService;
    }

    /** Get the currently active fiscal year, or null if none. */
    public function currentFiscalYear(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    /**
     * Get the default branch from config (by default_id or is_default), or null if branch is disabled.
     *
     * @return Model|null Instance of config('accounting.branch.model') or null
     */
    public function currentBranch(): ?Model
    {
        if ( ! config('accounting.branch.enabled', true)) {
            return null;
        }

        $modelClass = config('accounting.branch.model');
        if ( ! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        $id = config('accounting.branch.default_id');

        return $id ? $modelClass::find($id) : $modelClass::where('is_default', true)->first();
    }

    /**
     * Get a system account by config key (e.g. 'cash', 'bank', 'receivables', 'payables', 'sales_income', 'cost_of_goods', 'refund_expense').
     *
     * @throws InvalidArgumentException When the key is not configured in accounting.account.system_accounts
     */
    public function systemAccount(string $key): Account
    {
        return $this->accountService->getSystemAccount($key);
    }

    /** Package version string (from composer.json). */
    public function version(): string
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        return is_array($composer) && isset($composer['version'])
            ? (string) $composer['version']
            : '13.0.0';
    }
}
