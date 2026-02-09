# 10-multi-branch.md

# مدیریت چند شعبه

## Multi-Branch

---

## مقدمه

این بخش نحوه پیکربندی و استفاده از قابلیت چند شعبه‌ای پکیج حسابداری را شرح می‌دهد.

---

## ۱. مفهوم شعبه

### ۱.۱ تعریف

شعبه یک واحد سازمانی مستقل است که:
- عملیات مالی جداگانه دارد
- گزارش‌های مستقل تولید می‌کند
- در نهایت در گزارش‌های تجمیعی ادغام می‌شود

### ۱.۲ کاربردها

| سناریو | مثال |
|--------|------|
| شعب جغرافیایی | شعبه تهران، شعبه اصفهان، شعبه مشهد |
| فروشگاه‌های زنجیره‌ای | فروشگاه ۱، فروشگاه ۲، فروشگاه ۳ |
| واحدهای تجاری | بخش فروش، بخش خدمات، بخش تولید |
| شرکت‌های هولدینگ | شرکت الف، شرکت ب، شرکت ج |

### ۱.۳ ویژگی‌های کلیدی

| ویژگی | شرح |
|-------|-----|
| چارت حساب مشترک | همه شعب از یک درخت حسابداری استفاده می‌کنند |
| اسناد جداگانه | هر سند متعلق به یک شعبه است |
| شماره‌گذاری مستقل | هر شعبه شماره سند جداگانه دارد (اختیاری) |
| گزارش شعبه‌ای | هر شعبه گزارش مستقل دارد |
| گزارش تجمیعی | امکان ترکیب گزارش همه شعب |

---

## ۲. فعال‌سازی

### ۲.۱ تنظیمات Config

در `config/accounting.php`:

```php
'branch' => [
    // فعال بودن قابلیت شعبه
    'enabled' => true,
    
    // شناسه شعبه پیش‌فرض
    'default_id' => 1,
    
    // الزامی بودن شعبه در سند
    'required' => true,
    
    // تشخیص خودکار شعبه کاربر
    'auto_detect' => true,
    
    // تابع یافتن شعبه جاری
    'resolver' => null,
    
    // شماره‌گذاری سند به تفکیک شعبه
    'separate_numbering' => false,
],
```

### ۲.۲ غیرفعال کردن

اگر پروژه نیاز به شعبه ندارد:

```php
'branch' => [
    'enabled' => false,
],
```

---

## ۳. ساختار جدول branches

### ۳.۱ فیلدها

| فیلد | نوع | شرح |
|------|-----|-----|
| id | bigint | شناسه یکتا |
| code | varchar(20) | کد شعبه |
| title | varchar(100) | عنوان شعبه |
| is_active | boolean | وضعیت فعال |
| is_default | boolean | شعبه پیش‌فرض |
| meta | json | اطلاعات اضافی |

### ۳.۲ نمونه داده

| id | code | title | is_active | is_default |
|----|------|-------|-----------|------------|
| 1 | HQ | دفتر مرکزی | true | true |
| 2 | BR01 | شعبه تهران | true | false |
| 3 | BR02 | شعبه اصفهان | true | false |
| 4 | BR03 | شعبه مشهد | false | false |

---

## ۴. مدیریت شعب

### ۴.۱ ایجاد شعبه

```php
use YourVendor\Accounting\Models\Branch;

// روش ۱: مستقیم
$branch = Branch::create([
    'code' => 'BR04',
    'title' => 'شعبه شیراز',
    'is_active' => true,
    'is_default' => false,
    'meta' => [
        'address' => 'شیراز، خیابان زند',
        'phone' => '071-12345678',
        'manager' => 'آقای احمدی',
    ],
]);

// روش ۲: از طریق Facade
$branch = Accounting::branch()->create([
    'code' => 'BR04',
    'title' => 'شعبه شیراز',
]);
```

### ۴.۲ ویرایش شعبه

```php
$branch->update([
    'title' => 'شعبه شیراز - مرکزی',
    'meta' => [
        'address' => 'آدرس جدید',
    ],
]);
```

### ۴.۳ غیرفعال کردن شعبه

```php
$branch->update(['is_active' => false]);

// یا
Accounting::branch()->deactivate($branch);
```

### ۴.۴ تعیین شعبه پیش‌فرض

```php
Accounting::branch()->setDefault($branch);

// این کار سایر شعب را از حالت پیش‌فرض خارج می‌کند
```

### ۴.۵ دریافت شعب

```php
// همه شعب
$branches = Branch::all();

// شعب فعال
$activeBranches = Branch::active()->get();

// شعبه پیش‌فرض
$defaultBranch = Branch::default()->first();

// شعبه جاری
$currentBranch = Accounting::currentBranch();
```

---

## ۵. اتصال کاربر به شعبه

### ۵.۱ افزودن فیلد به جدول users

```php
// Migration
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('branch_id')
          ->nullable()
          ->constrained('branches')
          ->nullOnDelete();
});
```

### ۵.۲ تنظیم در Model

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
    ];
    
    public function branch()
    {
        return $this->belongsTo(\YourVendor\Accounting\Models\Branch::class);
    }
}
```

### ۵.۳ Resolver برای شعبه جاری

در `config/accounting.php`:

```php
'branch' => [
    'enabled' => true,
    'auto_detect' => true,
    'resolver' => function () {
        // از کاربر لاگین شده
        if (auth()->check()) {
            return auth()->user()->branch_id;
        }
        
        // از Session
        if (session()->has('current_branch_id')) {
            return session('current_branch_id');
        }
        
        // از Header (برای API)
        if (request()->hasHeader('X-Branch-ID')) {
            return (int) request()->header('X-Branch-ID');
        }
        
        // پیش‌فرض
        return config('accounting.branch.default_id');
    },
],
```

---

## ۶. ثبت سند با شعبه

### ۶.۱ شعبه خودکار

اگر `auto_detect` فعال باشد:

```php
// شعبه به صورت خودکار از Resolver گرفته می‌شود
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->debit($customer->account, 1000000)
    ->credit($salesAccount, 1000000)
    ->post();

// $document->branch_id = شعبه جاری کاربر
```

### ۶.۲ تعیین شعبه دستی

```php
$document = Accounting::document()
    ->type('sale')
    ->date(now())
    ->branch($tehranBranch)  // یا branch(2)
    ->debit($customer->account, 1000000)
    ->credit($salesAccount, 1000000)
    ->post();
```

### ۶.۳ سند بدون شعبه

اگر `required` غیرفعال باشد:

```php
$document = Accounting::document()
    ->type('adjustment')
    ->date(now())
    ->withoutBranch()  // بدون شعبه
    ->debit($account1, 1000000)
    ->credit($account2, 1000000)
    ->post();
```

---

## ۷. حساب‌های شعبه

### ۷.۱ حساب مشترک vs حساب شعبه‌ای

| نوع | شرح | مثال |
|-----|-----|------|
| مشترک | بین همه شعب مشترک | درآمد فروش، هزینه حقوق |
| شعبه‌ای | مختص یک شعبه | صندوق شعبه، بانک شعبه |

### ۷.۲ ایجاد حساب شعبه‌ای

```php
// صندوق شعبه تهران
$account = Accounting::account()->create([
    'parent_code' => '1101',
    'title' => 'صندوق شعبه تهران',
    'type' => 'asset',
    'nature' => 'debit',
    'branch_id' => 2,  // شعبه تهران
]);
```

### ۷.۳ Query حساب‌ها با شعبه

```php
use YourVendor\Accounting\Models\Account;

// حساب‌های یک شعبه
$branchAccounts = Account::where('branch_id', $branchId)->get();

// حساب‌های مشترک (بدون شعبه)
$sharedAccounts = Account::whereNull('branch_id')->get();

// حساب‌های قابل دسترس برای یک شعبه
$accessibleAccounts = Account::where(function ($query) use ($branchId) {
    $query->whereNull('branch_id')
          ->orWhere('branch_id', $branchId);
})->get();
```

---

## ۸. گزارش‌گیری شعبه‌ای

### ۸.۱ تراز آزمایشی شعبه

```php
// تراز یک شعبه
$trialBalance = Accounting::report()->trialBalance(
    branchId: 2  // شعبه تهران
);

// تراز همه شعب (تجمیعی)
$consolidatedTrialBalance = Accounting::report()->trialBalance(
    branchId: null  // همه شعب
);
```

### ۸.۲ سود و زیان شعبه

```php
$incomeStatement = Accounting::report()->incomeStatement(
    branchId: 2
);

echo "سود شعبه تهران: {$incomeStatement['summary']['net_profit']}";
```

### ۸.۳ ترازنامه شعبه

```php
$balanceSheet = Accounting::report()->balanceSheet(
    branchId: 2
);
```

### ۸.۴ مقایسه شعب

```php
$branchReport = Accounting::report()->branchReport();

foreach ($branchReport['branches'] as $branch) {
    echo "{$branch['branch']['title']}:\n";
    echo "  درآمد: {$branch['income']}\n";
    echo "  هزینه: {$branch['expenses']}\n";
    echo "  سود: {$branch['profit']}\n\n";
}
```

### ۸.۵ گزارش تجمیعی با جزئیات شعب

```php
$consolidatedReport = Accounting::report()->consolidatedIncomeStatement(
    showBranchDetails: true
);

// خروجی:
// [
//     'consolidated' => [...],  // جمع کل
//     'branches' => [
//         'شعبه تهران' => [...],
//         'شعبه اصفهان' => [...],
//     ],
// ]
```

---

## ۹. انتقال بین شعب

### ۹.۱ سند انتقال

برای انتقال وجه یا کالا بین شعب:

```php
public function transferBetweenBranches(
    Branch $fromBranch,
    Branch $toBranch,
    float $amount,
    string $description = ''
): void {
    $fromAccount = $this->getBranchCashAccount($fromBranch);
    $toAccount = $this->getBranchCashAccount($toBranch);
    $interBranchAccount = Accounting::systemAccount('inter_branch');
    
    // سند خروج از شعبه مبدا
    Accounting::document()
        ->type('transfer')
        ->date(now())
        ->branch($fromBranch)
        ->description("انتقال به {$toBranch->title}")
        ->debit($interBranchAccount, $amount)
        ->credit($fromAccount, $amount)
        ->post();
    
    // سند ورود به شعبه مقصد
    Accounting::document()
        ->type('transfer')
        ->date(now())
        ->branch($toBranch)
        ->description("دریافت از {$fromBranch->title}")
        ->debit($toAccount, $amount)
        ->credit($interBranchAccount, $amount)
        ->post();
}
```

### ۹.۲ حساب بین‌شعب

برای ردیابی انتقالات، یک حساب واسط تعریف کنید:

```php
// در Seeder
[
    'code' => '1199',
    'title' => 'حساب‌های بین‌شعب',
    'type' => 'asset',
    'nature' => 'debit',
]
```

---

## ۱۰. محدودیت دسترسی

### ۱۰.۱ Middleware شعبه

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BranchAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();
        
        // اگر کاربر شعبه ندارد، دسترسی کامل
        if (!$user->branch_id) {
            return $next($request);
        }
        
        // تنظیم شعبه در Session
        session(['current_branch_id' => $user->branch_id]);
        
        return $next($request);
    }
}
```

### ۱۰.۲ Policy برای Document

```php
<?php

namespace App\Policies;

use App\Models\User;
use YourVendor\Accounting\Models\Document;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        // ادمین همه را می‌بیند
        if ($user->is_admin) {
            return true;
        }
        
        // کاربر فقط اسناد شعبه خودش
        return $document->branch_id === $user->branch_id;
    }
    
    public function create(User $user): bool
    {
        return $user->branch_id !== null;
    }
    
    public function update(User $user, Document $document): bool
    {
        if (!$document->isEditable()) {
            return false;
        }
        
        return $document->branch_id === $user->branch_id;
    }
}
```

### ۱۰.۳ Global Scope برای فیلتر خودکار

```php
<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        
        // اگر کاربر شعبه دارد، فیلتر کن
        if ($user && $user->branch_id) {
            $builder->where('branch_id', $user->branch_id);
        }
    }
}
```

استفاده در Model:

```php
use YourVendor\Accounting\Models\Document;

class Document extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
```

### ۱۰.۴ بدون Scope (برای گزارش‌های تجمیعی)

```php
// همه اسناد بدون فیلتر شعبه
$allDocuments = Document::withoutGlobalScope(BranchScope::class)->get();
```

---

## ۱۱. تغییر شعبه

### ۱۱.۱ تغییر شعبه در Session

```php
// Controller
public function switchBranch(Request $request, Branch $branch)
{
    $user = auth()->user();
    
    // بررسی دسترسی به شعبه
    if (!$user->canAccessBranch($branch)) {
        abort(403);
    }
    
    session(['current_branch_id' => $branch->id]);
    
    return redirect()->back()->with('success', "شعبه به {$branch->title} تغییر کرد");
}
```

### ۱۱.۲ UI انتخاب شعبه

```php
// در Controller
public function index()
{
    $branches = auth()->user()->accessibleBranches();
    $currentBranch = Accounting::currentBranch();
    
    return view('dashboard', compact('branches', 'currentBranch'));
}
```

```html
<!-- در View -->
<select onchange="switchBranch(this.value)">
    @foreach($branches as $branch)
        <option value="{{ $branch->id }}" 
                {{ $currentBranch->id === $branch->id ? 'selected' : '' }}>
            {{ $branch->title }}
        </option>
    @endforeach
</select>
```

---

## ۱۲. شماره‌گذاری سند به تفکیک شعبه

### ۱۲.۱ فعال‌سازی

```php
'branch' => [
    'separate_numbering' => true,
],
```

### ۱۲.۲ نتیجه

| شعبه | شماره سند |
|------|-----------|
| دفتر مرکزی | HQ-1403-001 |
| شعبه تهران | BR01-1403-001 |
| شعبه اصفهان | BR02-1403-001 |

### ۱۲.۳ فرمت شماره سند

```php
'document' => [
    'number_format' => '{branch_code}-{fiscal_year}-{number:04d}',
],
```

| Placeholder | شرح |
|-------------|-----|
| {branch_code} | کد شعبه |
| {fiscal_year} | سال مالی |
| {number} | شماره ترتیبی |
| {number:04d} | شماره با صفر (0001) |

---

## ۱۳. Seeder شعب

### ۱۳.۱ Seeder پیش‌فرض

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use YourVendor\Accounting\Models\Branch;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'code' => 'HQ',
                'title' => 'دفتر مرکزی',
                'is_active' => true,
                'is_default' => true,
                'meta' => [
                    'address' => 'تهران، خیابان ولیعصر',
                    'phone' => '021-12345678',
                ],
            ],
            [
                'code' => 'BR01',
                'title' => 'شعبه تهران شرق',
                'is_active' => true,
                'is_default' => false,
                'meta' => [
                    'address' => 'تهران، نارمک',
                    'phone' => '021-22334455',
                ],
            ],
            [
                'code' => 'BR02',
                'title' => 'شعبه اصفهان',
                'is_active' => true,
                'is_default' => false,
                'meta' => [
                    'address' => 'اصفهان، چهارباغ',
                    'phone' => '031-12345678',
                ],
            ],
        ];
        
        foreach ($branches as $branch) {
            Branch::create($branch);
        }
    }
}
```

---

## ۱۴. مثال کامل

### ۱۴.۱ سناریو: فروشگاه زنجیره‌ای

```php
// ایجاد فروشگاه‌ها
$store1 = Branch::create(['code' => 'S01', 'title' => 'فروشگاه میرداماد']);
$store2 = Branch::create(['code' => 'S02', 'title' => 'فروشگاه ونک']);
$store3 = Branch::create(['code' => 'S03', 'title' => 'فروشگاه تجریش']);

// ایجاد صندوق برای هر فروشگاه
foreach ([$store1, $store2, $store3] as $store) {
    Accounting::account()->create([
        'parent_code' => '1101',
        'title' => "صندوق {$store->title}",
        'type' => 'asset',
        'nature' => 'debit',
        'branch_id' => $store->id,
    ]);
}

// ثبت فروش در فروشگاه ۱
$cashierStore1 = Cashier::where('branch_id', $store1->id)->first();

Accounting::document()
    ->type('sale')
    ->date(now())
    ->branch($store1)
    ->description('فروش نقدی')
    ->debit($cashierStore1->account, 500000)
    ->credit(Accounting::systemAccount('sales_income'), 500000)
    ->post();

// گزارش مقایسه فروشگاه‌ها
$comparison = Accounting::report()->branchReport(
    fromDate: now()->startOfMonth(),
    toDate: now()
);

foreach ($comparison['branches'] as $branch) {
    echo "{$branch['branch']['title']}: ";
    echo "فروش {$branch['income']} - ";
    echo "سود {$branch['profit']}\n";
}
```

### ۱۴.۲ سناریو: شرکت چندشعبه‌ای

```php
class MultibranchAccountingService
{
    public function recordSaleAtBranch(
        Branch $branch,
        User $customer,
        float $amount,
        string $paymentMethod
    ): Document {
        $paymentAccount = $this->getPaymentAccount($branch, $paymentMethod);
        
        return Accounting::document()
            ->type('sale')
            ->date(now())
            ->branch($branch)
            ->description("فروش به {$customer->name}")
            ->debit($paymentAccount, $amount)
            ->credit(Accounting::systemAccount('sales_income'), $amount)
            ->post();
    }
    
    public function transferCashToCentral(Branch $branch, float $amount): void
    {
        $branchCash = $this->getBranchCashAccount($branch);
        $centralCash = $this->getCentralCashAccount();
        $interBranch = Accounting::systemAccount('inter_branch');
        
        // خروج از شعبه
        Accounting::document()
            ->type('transfer')
            ->date(now())
            ->branch($branch)
            ->description('واریز به مرکز')
            ->debit($interBranch, $amount)
            ->credit($branchCash, $amount)
            ->post();
        
        // ورود به مرکز
        Accounting::document()
            ->type('transfer')
            ->date(now())
            ->branch(Branch::default()->first())
            ->description("دریافت از {$branch->title}")
            ->debit($centralCash, $amount)
            ->credit($interBranch, $amount)
            ->post();
    }
    
    public function getBranchPerformance(Branch $branch, Carbon $from, Carbon $to): array
    {
        $income = Accounting::report()->incomeStatement(
            branchId: $branch->id,
            fromDate: $from,
            toDate: $to
        );
        
        $documents = Document::where('branch_id', $branch->id)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'posted')
            ->count();
        
        return [
            'branch' => $branch,
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => $income['income']['total'],
            'expenses' => $income['expenses']['total'],
            'profit' => $income['summary']['net_profit'],
            'margin' => $income['summary']['net_margin'],
            'documents_count' => $documents,
        ];
    }
    
    private function getPaymentAccount(Branch $branch, string $method): Account
    {
        if ($method === 'cash') {
            return Account::where('branch_id', $branch->id)
                ->where('code', 'like', '1101%')
                ->first();
        }
        
        return Account::where('branch_id', $branch->id)
            ->where('code', 'like', '1102%')
            ->first();
    }
}
```

---

## ۱۵. نکات مهم

### ۱۵.۱ عملکرد

| نکته | توضیح |
|------|-------|
| Index | فیلد branch_id باید Index داشته باشد |
| Eager Loading | هنگام لود اسناد، شعبه را Eager Load کنید |
| Cache | گزارش‌های شعبه‌ای را Cache کنید |

### ۱۵.۲ یکپارچگی داده

| نکته | توضیح |
|------|-------|
| حساب بین‌شعب | همیشه باید صفر باشد |
| انتقالات | باید جفت باشند (خروج و ورود) |
| گزارش تجمیعی | باید با جمع شعب برابر باشد |

### ۱۵.۳ امنیت

| نکته | توضیح |
|------|-------|
| دسترسی | کاربران فقط اسناد شعبه خود را ببینند |
| تغییر شعبه سند | پس از ثبت قطعی ممنوع |
| حذف شعبه | شعبه دارای سند قابل حذف نیست |

---

## ۱۶. خلاصه

| موضوع | نکته کلیدی |
|-------|------------|
| فعال‌سازی | `branch.enabled = true` در Config |
| تعیین شعبه | خودکار با Resolver یا دستی |
| گزارش شعبه‌ای | پارامتر branchId در متدهای گزارش |
| گزارش تجمیعی | branchId = null |
| انتقال بین شعب | استفاده از حساب واسط |
| امنیت | Global Scope و Policy |

---

[→ ادامه: چندزبانگی (11-multi-language.md)](11-multi-language.md)

[← بازگشت: گزارش‌ها (09-reports.md)](09-reports.md)

[⌂ فهرست (00-index.md)](00-index.md)
