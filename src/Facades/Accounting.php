<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentBuilder;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ClosingService;
use Karnoweb\Accounting\Services\OpeningService;
use Karnoweb\Accounting\Services\PostingService;
use Karnoweb\Accounting\Services\ReportService;

/**
 * Facade for the Accounting package.
 *
 * Provides fluent access to documents, accounts, balances, reports, and fiscal years.
 * Use this facade for IDE autocomplete and type-safe accounting operations.
 *
 * @method static DocumentBuilder   document()                 Start building a new document (fluent API).
 * @method static AccountService    account()                  Access account CRUD and lookup.
 * @method static BalanceService    balance()                  Get balances and turnover.
 * @method static ReportService     report()                   Run accounting reports.
 * @method static FiscalYearService fiscalYear()               Resolve fiscal years and run lifecycle transitions.
 * @method static OpeningService    opening()                  Post manual opening journals and carry-forward.
 * @method static ClosingService    closing()                  Close P&L into retained earnings while the year is active.
 * @method static PostingService    posting()                  Ask whether a document may be posted (FY + date).
 * @method static FiscalYear|null   currentFiscalYear()        Get the active fiscal year, or null.
 * @method static Model|null        currentBranch()            Get default branch from config, or null if disabled.
 * @method static Account           systemAccount(string $key) Get system account by key (e.g. 'cash', 'bank').
 * @method static string            version()                  Package version string.
 *
 * @mixin \Karnoweb\Accounting\AccountingManager
 *
 * @see \Karnoweb\Accounting\AccountingManager
 */
class Accounting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'accounting';
    }
}
