<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Karnoweb\Accounting\Enums\AccountType;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Models\Account;
use Karnoweb\Accounting\Models\Branch;
use Karnoweb\Accounting\Models\FiscalYear;

class DefaultAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['code' => 'HQ'],
            [
                'title' => 'دفتر مرکزی',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        if (FiscalYear::where('is_current', true)->doesntExist()) {
            $start = now()->startOfYear()->format('Y-m-d');
            $end = now()->endOfYear()->format('Y-m-d');
            FiscalYear::firstOrCreate(
                ['start_date' => $start, 'end_date' => $end],
                [
                    'title' => 'سال مالی ' . now()->year,
                    'status' => FiscalYearStatus::ACTIVE,
                    'is_current' => true,
                ]
            );
        }

        if (Account::where('code', '1')->exists()) {
            return;
        }

        $this->createAccounts($branch->id);
    }

    private function createAccounts(?int $branchId): void
    {
        $accounts = [
            ['code' => '1', 'title' => 'دارایی‌ها', 'level' => 0, 'type' => AccountType::ASSET, 'parent_id' => null],
            ['code' => '2', 'title' => 'بدهی‌ها', 'level' => 0, 'type' => AccountType::LIABILITY, 'parent_id' => null],
            ['code' => '3', 'title' => 'سرمایه', 'level' => 0, 'type' => AccountType::EQUITY, 'parent_id' => null],
            ['code' => '4', 'title' => 'درآمدها', 'level' => 0, 'type' => AccountType::INCOME, 'parent_id' => null],
            ['code' => '5', 'title' => 'هزینه‌ها', 'level' => 0, 'type' => AccountType::EXPENSE, 'parent_id' => null],
            ['code' => '11', 'title' => 'دارایی جاری', 'level' => 1, 'type' => AccountType::ASSET, 'parent_code' => '1'],
            ['code' => '21', 'title' => 'بدهی جاری', 'level' => 1, 'type' => AccountType::LIABILITY, 'parent_code' => '2'],
            ['code' => '31', 'title' => 'سرمایه', 'level' => 1, 'type' => AccountType::EQUITY, 'parent_code' => '3'],
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
        ];

        $byCode = [];
        foreach ($accounts as $row) {
            $parentId = null;
            if (isset($row['parent_code'])) {
                $parentId = $byCode[$row['parent_code']] ?? null;
            } elseif (isset($row['parent_id'])) {
                $parentId = $row['parent_id'];
            }

            $type = $row['type'];
            $nature = $type->defaultNature();

            $account = Account::create([
                'parent_id' => $parentId,
                'branch_id' => $branchId,
                'code' => $row['code'],
                'title' => $row['title'],
                'level' => $row['level'],
                'type' => $type->value,
                'nature' => $nature->value,
                'is_active' => true,
                'is_system' => $row['is_system'] ?? false,
                'allow_direct_posting' => $row['level'] === 3,
            ]);

            $byCode[$row['code']] = $account->id;
        }
    }
}
