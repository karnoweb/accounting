# 14-implementation/14b-migrations.md

# پیاده‌سازی - Migrations

## Implementation - Migrations

---

## مقدمه

این بخش شامل کد کامل Migration های پکیج حسابداری است. ترتیب اجرا بر اساس وابستگی جداول تعیین شده است.

---

## ۱. Migration شعب (branches)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            
            // اطلاعات اصلی
            $table->string('code', 20)->unique();
            $table->string('title', 100);
            
            // وضعیت
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            
            // اطلاعات اضافی
            $table->json('meta')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('is_active');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
```

---

## ۲. Migration سال‌های مالی (fiscal_years)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            
            // اطلاعات اصلی
            $table->string('title', 100);
            $table->date('start_date');
            $table->date('end_date');
            
            // وضعیت
            $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
            $table->boolean('is_current')->default(false);
            
            // افتتاحیه و اختتامیه
            $table->boolean('opening_done')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->unique(['start_date', 'end_date']);
            $table->index('status');
            $table->index('is_current');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
```

---

## ۳. Migration حساب‌ها (accounts)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            
            // ساختار درختی
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('accounts')
                  ->nullOnDelete();
            
            // شعبه
            $table->foreignId('branch_id')
                  ->nullable()
                  ->constrained('branches')
                  ->nullOnDelete();
            
            // کدینگ و عنوان
            $table->string('code', 20)->unique();
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            
            // سطح و نوع
            $table->tinyInteger('level')->unsigned()->default(0);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->enum('nature', ['debit', 'credit']);
            
            // وضعیت
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('allow_direct_posting')->default(true);
            
            // اتصال به موجودیت خارجی
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            
            // مانده کش شده
            $table->decimal('cached_balance', 15, 2)->default(0);
            $table->timestamp('balance_updated_at')->nullable();
            
            // اطلاعات اضافی
            $table->json('meta')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('parent_id');
            $table->index('branch_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('level');
            $table->index('type');
            $table->index('nature');
            $table->index('is_active');
            $table->index('is_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
```

---

## ۴. Migration مراکز هزینه (cost_centers)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            
            // اطلاعات اصلی
            $table->string('code', 20)->unique();
            $table->string('title', 100);
            $table->string('description', 255)->nullable();
            
            // وضعیت
            $table->boolean('is_active')->default(true);
            
            // اطلاعات اضافی
            $table->json('meta')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
```

---

## ۵. Migration اسناد (documents)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $userTable = config('accounting.user.table', 'users');
        $userForeignKey = config('accounting.user.foreign_key', 'user_id');
        
        Schema::create('documents', function (Blueprint $table) use ($userTable, $userForeignKey) {
            $table->id();
            
            // سال مالی
            $table->foreignId('fiscal_year_id')
                  ->constrained('fiscal_years')
                  ->restrictOnDelete();
            
            // شعبه
            $table->foreignId('branch_id')
                  ->nullable()
                  ->constrained('branches')
                  ->nullOnDelete();
            
            // شماره سند
            $table->unsignedBigInteger('number');
            $table->string('reference', 50)->nullable();
            
            // تاریخ
            $table->date('date');
            $table->timestamp('posted_at')->nullable();
            
            // نوع و وضعیت
            $table->string('type', 50);
            $table->enum('status', ['draft', 'pending', 'approved', 'posted', 'voided'])
                  ->default('draft');
            
            // توضیحات
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();
            
            // منبع خارجی
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            
            // کاربران
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            
            // اطلاعات اضافی
            $table->json('meta')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->unique(['fiscal_year_id', 'number']);
            $table->index('date');
            $table->index('type');
            $table->index('status');
            $table->index(['source_type', 'source_id']);
            $table->index('reference');
            $table->index('created_by');
        });
        
        // Add foreign keys for user fields if table exists
        if (Schema::hasTable($userTable)) {
            Schema::table('documents', function (Blueprint $table) use ($userTable) {
                $table->foreign('created_by')
                      ->references('id')
                      ->on($userTable)
                      ->nullOnDelete();
                
                $table->foreign('approved_by')
                      ->references('id')
                      ->on($userTable)
                      ->nullOnDelete();
                
                $table->foreign('posted_by')
                      ->references('id')
                      ->on($userTable)
                      ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

---

## ۶. Migration آیتم‌های سند (document_items)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            
            // سند
            $table->foreignId('document_id')
                  ->constrained('documents')
                  ->cascadeOnDelete();
            
            // حساب
            $table->foreignId('account_id')
                  ->constrained('accounts')
                  ->restrictOnDelete();
            
            // مرکز هزینه
            $table->foreignId('cost_center_id')
                  ->nullable()
                  ->constrained('cost_centers')
                  ->nullOnDelete();
            
            // مبلغ
            $table->decimal('amount', 15, 2);
            $table->tinyInteger('sign'); // +1 = debit, -1 = credit
            
            // مبالغ محاسبه شده (برای Query راحت‌تر)
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            
            // توضیحات
            $table->string('description', 255)->nullable();
            
            // ترتیب
            $table->unsignedSmallInteger('order')->default(0);
            
            // اطلاعات اضافی
            $table->json('meta')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('account_id');
            $table->index('cost_center_id');
            $table->index(['document_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};
```

---

## ۷. Migration لاگ اسناد (document_logs)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $userTable = config('accounting.user.table', 'users');
        
        Schema::create('document_logs', function (Blueprint $table) use ($userTable) {
            $table->id();
            
            // سند
            $table->foreignId('document_id')
                  ->constrained('documents')
                  ->cascadeOnDelete();
            
            // کاربر
            $table->unsignedBigInteger('user_id')->nullable();
            
            // عملیات
            $table->enum('action', [
                'created',
                'updated',
                'submitted',
                'approved',
                'rejected',
                'posted',
                'voided',
                'restored'
            ]);
            
            // توضیحات
            $table->string('description', 255)->nullable();
            
            // مقادیر قبل و بعد
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            // اطلاعات درخواست
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            
            // زمان
            $table->timestamp('created_at');
            
            // Indexes
            $table->index(['document_id', 'created_at']);
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });
        
        // Add foreign key for user if table exists
        if (Schema::hasTable($userTable)) {
            Schema::table('document_logs', function (Blueprint $table) use ($userTable) {
                $table->foreign('user_id')
                      ->references('id')
                      ->on($userTable)
                      ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_logs');
    }
};
```

---

## ۸. Migration خلاصه مانده حساب‌ها (account_balances) - اختیاری

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            
            // حساب و سال مالی
            $table->foreignId('account_id')
                  ->constrained('accounts')
                  ->cascadeOnDelete();
            
            $table->foreignId('fiscal_year_id')
                  ->constrained('fiscal_years')
                  ->cascadeOnDelete();
            
            // مانده افتتاحیه
            $table->decimal('opening_debit', 15, 2)->default(0);
            $table->decimal('opening_credit', 15, 2)->default(0);
            
            // گردش دوره
            $table->decimal('period_debit', 15, 2)->default(0);
            $table->decimal('period_credit', 15, 2)->default(0);
            
            // مانده پایانی
            $table->decimal('closing_balance', 15, 2)->default(0);
            
            // زمان محاسبه
            $table->timestamp('calculated_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['account_id', 'fiscal_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
```

---

## ۹. Migration خلاصه ماهانه (account_monthly_summaries) - اختیاری

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_monthly_summaries', function (Blueprint $table) {
            $table->id();
            
            // حساب و سال مالی
            $table->foreignId('account_id')
                  ->constrained('accounts')
                  ->cascadeOnDelete();
            
            $table->foreignId('fiscal_year_id')
                  ->constrained('fiscal_years')
                  ->cascadeOnDelete();
            
            // ماه
            $table->unsignedTinyInteger('month'); // 1-12
            
            // مانده‌ها
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('debit_sum', 15, 2)->default(0);
            $table->decimal('credit_sum', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            
            // تعداد تراکنش
            $table->unsignedInteger('transaction_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['account_id', 'fiscal_year_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_monthly_summaries');
    }
};
```

---

## ۱۰. Migration ترجمه حساب‌ها (account_translations) - اختیاری

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_translations', function (Blueprint $table) {
            $table->id();
            
            // حساب
            $table->foreignId('account_id')
                  ->constrained('accounts')
                  ->cascadeOnDelete();
            
            // زبان
            $table->string('locale', 5);
            
            // ترجمه
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['account_id', 'locale']);
            $table->index('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_translations');
    }
};
```

---

## ۱۱. ترتیب اجرای Migration ها

| ترتیب | فایل | وابستگی |
|-------|------|---------|
| ۱ | create_branches_table | - |
| ۲ | create_fiscal_years_table | - |
| ۳ | create_accounts_table | branches |
| ۴ | create_cost_centers_table | - |
| ۵ | create_documents_table | fiscal_years, branches |
| ۶ | create_document_items_table | documents, accounts, cost_centers |
| ۷ | create_document_logs_table | documents |
| ۸ | create_account_balances_table | accounts, fiscal_years |
| ۹ | create_account_monthly_summaries_table | accounts, fiscal_years |
| ۱۰ | create_account_translations_table | accounts |

---

## ۱۲. نام‌گذاری فایل‌ها

```
database/migrations/
├── 2024_01_01_000001_create_branches_table.php
├── 2024_01_01_000002_create_fiscal_years_table.php
├── 2024_01_01_000003_create_accounts_table.php
├── 2024_01_01_000004_create_cost_centers_table.php
├── 2024_01_01_000005_create_documents_table.php
├── 2024_01_01_000006_create_document_items_table.php
├── 2024_01_01_000007_create_document_logs_table.php
├── 2024_01_01_000008_create_account_balances_table.php
├── 2024_01_01_000009_create_account_monthly_summaries_table.php
└── 2024_01_01_000010_create_account_translations_table.php
```

---

## ۱۳. نکات مهم

### ۱۳.۱ پیشوند جداول

اگر نیاز به پیشوند دارید:

```php
// در ServiceProvider
public function boot(): void
{
    $prefix = config('accounting.general.prefix', '');
    
    if ($prefix) {
        // تنظیم table name در Model ها
    }
}
```

### ۱۳.۲ Foreign Key به جدول users

برای جلوگیری از خطا اگر جدول users وجود نداشته باشد:

```php
if (Schema::hasTable($userTable)) {
    Schema::table('documents', function (Blueprint $table) use ($userTable) {
        $table->foreign('created_by')
              ->references('id')
              ->on($userTable)
              ->nullOnDelete();
    });
}
```

### ۱۳.۳ Precision مبالغ

```php
// برای ریال ایران (بدون اعشار)
$table->decimal('amount', 15, 0);

// برای ارزهای با اعشار
$table->decimal('amount', 15, 2);

// برای دقت بالاتر
$table->decimal('amount', 18, 4);
```

### ۱۳.۴ Indexes مهم

```php
// برای جستجوی سریع اسناد
$table->index(['fiscal_year_id', 'date', 'status']);

// برای گزارش مانده
$table->index(['account_id', 'document_id']);

// برای جستجوی موجودیت
$table->index(['entity_type', 'entity_id']);
```

---

## ۱۴. Rollback

برای Rollback کردن همه Migration ها:

```bash
php artisan migrate:rollback --step=10
```

برای Fresh:

```bash
php artisan migrate:fresh
```

---

[→ ادامه: پیاده‌سازی - Services (14c-services.md)](14c-services.md)

[← بازگشت: پیاده‌سازی - Models (14a-models.md)](14a-models.md)

[⌂ فهرست (00-index.md)](../00-index.md)
