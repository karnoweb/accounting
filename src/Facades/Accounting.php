<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Facades;

use Illuminate\Support\Facades\Facade;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Branch;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\AccountService;
use Karnoweb\Accounting\Services\BalanceService;
use Karnoweb\Accounting\Services\DocumentBuilder;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Services\ReportService;

/**
 * @method static DocumentBuilder   document()
 * @method static AccountService    account()
 * @method static BalanceService    balance()
 * @method static ReportService     report()
 * @method static FiscalYearService fiscalYear()
 * @method static FiscalYear|null   currentFiscalYear()
 * @method static Branch|null       currentBranch()
 * @method static Account           systemAccount(string $key)
 * @method static string            version()
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
