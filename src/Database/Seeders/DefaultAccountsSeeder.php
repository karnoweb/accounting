<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use InvalidArgumentException;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\FiscalYear;
use Karnoweb\Accounting\Services\FiscalYearService;
use Karnoweb\Accounting\Support\AccountHierarchy;

class DefaultAccountsSeeder extends Seeder
{
    /** Digit lengths the hardcoded codes below (e.g. '1101', '110101') assume per level. */
    private const ASSUMED_CODE_LENGTH = [1, 2, 4, 6];

    /** Run the seeder. If config('accounting.seed.branch_id') is set, uses that branch and does not create one. */
    public function run(): void
    {
        $this->assertCodeLengthCompatible();

        $branchId = config('accounting.seed.branch_id');

        if ($branchId === null) {
            $branchModel = config('accounting.branch.model');
            $branch = $branchModel && class_exists($branchModel)
                ? $branchModel::updateOrCreate(
                    ['code' => 'HQ'],
                    [
                        'title' => 'دفتر مرکزی',
                        'is_active' => true,
                        'is_default' => true,
                    ]
                )
                : null;
            $branchId = $branch?->id;
        }

        $this->ensureFiscalYear();
        $this->syncAccounts($branchId);
    }

    /**
     * Sync default accounts and fiscal year for a given branch (e.g. when creating a new club/branch).
     * Call this after creating a new branch so it gets the same chart of accounts.
     */
    public static function syncForBranch(int $branchId): void
    {
        $seeder = new self;
        $seeder->assertCodeLengthCompatible();
        $seeder->ensureFiscalYear();
        $seeder->syncAccounts($branchId);
    }

    /**
     * The default chart's codes are literal digit strings ('1', '11', '1101',
     * '110101', ...) sized for code_length = [1,2,4,6]. If the app overrides
     * code_length (e.g. to fit a branch-code segment into detail codes), those
     * literals silently stop matching AccountService::generateCode()'s expected
     * length per level, so the next auto-generated sibling collides with — or
     * ignores — these seeded rows instead of continuing the sequence.
     *
     * Fails loudly instead of seeding a chart that will corrupt code generation
     * later. Fix by either keeping code_length = [1,2,4,6] or supplying your own
     * chart via accounting.account.custom_seed / a custom seeder.
     */
    private function assertCodeLengthCompatible(): void
    {
        $configured = config('accounting.account.code_length', self::ASSUMED_CODE_LENGTH);

        if (array_slice($configured, 0, count(self::ASSUMED_CODE_LENGTH)) !== self::ASSUMED_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'DefaultAccountsSeeder codes assume accounting.account.code_length = [%s] (current: [%s]). '
                . 'Set code_length back to [%s], or provide your own chart via accounting.account.custom_seed '
                . 'instead of running this default seeder.',
                implode(',', self::ASSUMED_CODE_LENGTH),
                implode(',', $configured),
                implode(',', self::ASSUMED_CODE_LENGTH)
            ));
        }
    }

    private function ensureFiscalYear(): void
    {
        $service = app(FiscalYearService::class);

        if ($service->current()) {
            return;
        }

        $start = now()->startOfYear()->format('Y-m-d');
        $end = now()->endOfYear()->format('Y-m-d');

        $existing = FiscalYear::query()
            ->whereDate('start_date', $start)
            ->whereDate('end_date', $end)
            ->first();

        if ($existing) {
            if ( ! $existing->isClosed()) {
                $service->activate($existing);
            }

            return;
        }

        $fiscalYear = $service->create([
            'title' => 'سال مالی ' . now()->year,
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $service->activate($fiscalYear);
    }

    private function syncAccounts(?int $branchId): void
    {
        $defaults = $this->defaultAccountsDefinition();
        $custom = config('accounting.account.custom_seed', []);
        $accounts = array_merge($defaults, $custom);

        $byCode = [];
        foreach ($accounts as $row) {
            $parentId = null;
            if (isset($row['parent_code'])) {
                $parentId = $byCode[$row['parent_code']] ?? null;
            } elseif (isset($row['parent_id'])) {
                $parentId = $row['parent_id'];
            }

            $type = $row['type'] instanceof AccountType
                ? $row['type']
                : AccountType::from($row['type']);
            $nature = $type->defaultNature();

            $attributes = [
                'parent_id' => $parentId,
                'branch_id' => $branchId,
                'title' => $row['title'],
                'level' => $row['level'],
                'type' => $type->value,
                'nature' => $nature->value,
                'is_active' => true,
                'is_system' => $row['is_system'] ?? false,
                'allow_direct_posting' => $row['level'] === AccountHierarchy::postingLevel(),
            ];

            $account = Account::updateOrCreate(
                [
                    'code' => $row['code'],
                    'branch_id' => $branchId,
                ],
                $attributes
            );

            $byCode[$row['code']] = $account->id;
        }
    }

    /** @return array<int, array{code: string, title: string, level: int, type: AccountType, parent_id?: null, parent_code?: string, is_system?: bool}> */
    private function defaultAccountsDefinition(): array
    {
        return [
            ['code' => '1', 'title' => 'دارایی‌ها', 'level' => 0, 'type' => AccountType::ASSET, 'parent_id' => null],
            ['code' => '2', 'title' => 'بدهی‌ها', 'level' => 0, 'type' => AccountType::LIABILITY, 'parent_id' => null],
            ['code' => '3', 'title' => 'سرمایه', 'level' => 0, 'type' => AccountType::EQUITY, 'parent_id' => null],
            ['code' => '4', 'title' => 'درآمدها', 'level' => 0, 'type' => AccountType::INCOME, 'parent_id' => null],
            ['code' => '5', 'title' => 'هزینه‌ها', 'level' => 0, 'type' => AccountType::EXPENSE, 'parent_id' => null],
            ['code' => '11', 'title' => 'دارایی جاری', 'level' => 1, 'type' => AccountType::ASSET, 'parent_code' => '1'],
            ['code' => '21', 'title' => 'بدهی جاری', 'level' => 1, 'type' => AccountType::LIABILITY, 'parent_code' => '2'],
            ['code' => '31', 'title' => 'سرمایه', 'level' => 1, 'type' => AccountType::EQUITY, 'parent_code' => '3'],
            ['code' => '3101', 'title' => 'سود انباشته', 'level' => 2, 'type' => AccountType::EQUITY, 'parent_code' => '31'],
            ['code' => '310101', 'title' => 'سود انباشته', 'level' => 3, 'type' => AccountType::EQUITY, 'parent_code' => '3101', 'is_system' => true],
            ['code' => '41', 'title' => 'درآمد عملیاتی', 'level' => 1, 'type' => AccountType::INCOME, 'parent_code' => '4'],
            ['code' => '51', 'title' => 'هزینه عملیاتی', 'level' => 1, 'type' => AccountType::EXPENSE, 'parent_code' => '5'],
            ['code' => '1101', 'title' => 'موجودی نقد', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '1102', 'title' => 'بانک‌ها', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '1103', 'title' => 'حساب‌های دریافتنی', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '2101', 'title' => 'حساب‌های پرداختنی', 'level' => 2, 'type' => AccountType::LIABILITY, 'parent_code' => '21'],
            ['code' => '4101', 'title' => 'درآمد فروش', 'level' => 2, 'type' => AccountType::INCOME, 'parent_code' => '41'],
            ['code' => '5101', 'title' => 'بهای تمام شده', 'level' => 2, 'type' => AccountType::EXPENSE, 'parent_code' => '51'],
            ['code' => '5201', 'title' => 'سایر هزینه‌ها', 'level' => 2, 'type' => AccountType::EXPENSE, 'parent_code' => '51'],
            ['code' => '110101', 'title' => 'صندوق', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1101', 'is_system' => true],
            ['code' => '110201', 'title' => 'بانک اصلی', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1102', 'is_system' => true],
            ['code' => '410101', 'title' => 'فروش کالا', 'level' => 3, 'type' => AccountType::INCOME, 'parent_code' => '4101', 'is_system' => true],
            ['code' => '510101', 'title' => 'بهای تمام شده', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5101', 'is_system' => true],
            ['code' => '520101', 'title' => 'هزینه استرداد', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5201', 'is_system' => true],
            ['code' => '110300', 'title' => 'حساب‌های دریافتنی تجاری', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1103', 'is_system' => true],
            ['code' => '210101', 'title' => 'حساب‌های پرداختنی تجاری', 'level' => 3, 'type' => AccountType::LIABILITY, 'parent_code' => '2101', 'is_system' => true],
            ['code' => '1109', 'title' => 'موجودی کالا', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '110901', 'title' => 'موجودی کالا', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1109', 'is_system' => true],
            ['code' => '1111', 'title' => 'وام کارکنان', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '111101', 'title' => 'وام کارکنان', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1111', 'is_system' => true],
            ['code' => '1104', 'title' => 'تنخواه‌گردان', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '110401', 'title' => 'تنخواه‌گردان', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1104', 'is_system' => true],
            ['code' => '1105', 'title' => 'درگاه پرداخت', 'level' => 2, 'type' => AccountType::ASSET, 'parent_code' => '11'],
            ['code' => '110501', 'title' => 'تسویه درگاه پرداخت', 'level' => 3, 'type' => AccountType::ASSET, 'parent_code' => '1105', 'is_system' => true],
            ['code' => '49', 'title' => 'کاهنده فروش', 'level' => 1, 'type' => AccountType::INCOME, 'parent_code' => '4'],
            ['code' => '4901', 'title' => 'تخفیفات فروش', 'level' => 2, 'type' => AccountType::INCOME, 'parent_code' => '49'],
            ['code' => '490101', 'title' => 'تخفیفات فروش', 'level' => 3, 'type' => AccountType::INCOME, 'parent_code' => '4901', 'is_system' => true],
            ['code' => '4902', 'title' => 'برگشت از فروش', 'level' => 2, 'type' => AccountType::INCOME, 'parent_code' => '49'],
            ['code' => '490201', 'title' => 'برگشت از فروش', 'level' => 3, 'type' => AccountType::INCOME, 'parent_code' => '4902', 'is_system' => true],
            ['code' => '4102', 'title' => 'سایر درآمدهای عملیاتی', 'level' => 2, 'type' => AccountType::INCOME, 'parent_code' => '41'],
            ['code' => '410201', 'title' => 'اضافات انبارگردانی', 'level' => 3, 'type' => AccountType::INCOME, 'parent_code' => '4102', 'is_system' => true],
            ['code' => '2104', 'title' => 'مالیات پرداختنی', 'level' => 2, 'type' => AccountType::LIABILITY, 'parent_code' => '21'],
            ['code' => '210401', 'title' => 'مالیات بر ارزش افزوده', 'level' => 3, 'type' => AccountType::LIABILITY, 'parent_code' => '2104', 'is_system' => true],
            ['code' => '210402', 'title' => 'مالیات حقوق پرداختنی', 'level' => 3, 'type' => AccountType::LIABILITY, 'parent_code' => '2104', 'is_system' => true],
            ['code' => '2105', 'title' => 'حقوق و دستمزد پرداختنی', 'level' => 2, 'type' => AccountType::LIABILITY, 'parent_code' => '21'],
            ['code' => '210501', 'title' => 'حقوق و دستمزد پرداختنی', 'level' => 3, 'type' => AccountType::LIABILITY, 'parent_code' => '2105', 'is_system' => true],
            ['code' => '210502', 'title' => 'بیمه حقوق پرداختنی', 'level' => 3, 'type' => AccountType::LIABILITY, 'parent_code' => '2105', 'is_system' => true],
            ['code' => '2106', 'title' => 'بدهی کیف پول مشتریان', 'level' => 2, 'type' => AccountType::LIABILITY, 'parent_code' => '21'],
            ['code' => '5202', 'title' => 'هزینه حقوق و دستمزد', 'level' => 2, 'type' => AccountType::EXPENSE, 'parent_code' => '51'],
            ['code' => '520201', 'title' => 'هزینه حقوق و دستمزد', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5202', 'is_system' => true],
            ['code' => '520202', 'title' => 'سهم کارفرما بیمه', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5202', 'is_system' => true],
            ['code' => '5203', 'title' => 'هزینه‌های مالی و بانکی', 'level' => 2, 'type' => AccountType::EXPENSE, 'parent_code' => '51'],
            ['code' => '520301', 'title' => 'کارمزد بانکی', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5203', 'is_system' => true],
            ['code' => '5204', 'title' => 'هزینه‌های موجودی', 'level' => 2, 'type' => AccountType::EXPENSE, 'parent_code' => '51'],
            ['code' => '520401', 'title' => 'ضایعات و کسری انبار', 'level' => 3, 'type' => AccountType::EXPENSE, 'parent_code' => '5204', 'is_system' => true],
        ];
    }
}
