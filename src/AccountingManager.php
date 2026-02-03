<?php

declare(strict_types=1);

namespace Karnoweb\Accounting;

use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Branch;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentBuilder;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ReportService;

class AccountingManager
{
    public function __construct(
        protected DocumentBuilder $documentBuilder,
        protected AccountService $accountService,
        protected BalanceService $balanceService,
        protected ReportService $reportService,
        protected FiscalYearService $fiscalYearService
    ) {}

    public function document(): DocumentBuilder
    {
        return $this->documentBuilder;
    }

    public function account(): AccountService
    {
        return $this->accountService;
    }

    public function balance(): BalanceService
    {
        return $this->balanceService;
    }

    public function report(): ReportService
    {
        return $this->reportService;
    }

    public function fiscalYear(): FiscalYearService
    {
        return $this->fiscalYearService;
    }

    public function currentFiscalYear(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    public function currentBranch(): ?Branch
    {
        if ( ! config('accounting.branch.enabled', true)) {
            return null;
        }

        $id = config('accounting.branch.default_id');

        return $id ? Branch::find($id) : Branch::where('is_default', true)->first();
    }

    public function systemAccount(string $key): Account
    {
        return $this->accountService->getSystemAccount($key);
    }

    public function version(): string
    {
        return '1.0.0';
    }
}
