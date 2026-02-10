# 04-database-schema.md

# ساختار دیتابیس

## Database Schema

---

## مقدمه

این بخش ساختار کامل دیتابیس پکیج حسابداری را شرح می‌دهد. شامل جداول، فیلدها، روابط، ایندکس‌ها و محدودیت‌ها.

---

## ۱. نمای کلی جداول

### ۱.۱ لیست جداول

پکیج فقط جداول زیر را ایجاد می‌کند (با پیشوند از `config/accounting.general.prefix`، مثلاً `acc_`):

| # | جدول | شرح | تعداد فیلد |
|---|------|-----|------------|
| ۱ | fiscal_years | سال‌های مالی | ۱۱ |
| ۲ | accounts | حساب‌های مالی | ۱۸ |
| ۳ | cost_centers | مراکز هزینه | ۸ |
| ۴ | documents | اسناد حسابداری | ۱۷ |
| ۵ | document_items | آیتم‌های اسناد | ۱۰ |
| ۶ | document_logs | لاگ تغییرات اسناد | ۱۰ |

**جدول `branches` توسط پکیج ایجاد نمی‌شود.** در جداول `accounts` و `documents` فیلد **`branch_id`** (nullable) وجود دارد؛ در صورت نیاز، جدول/مدل شعبه را در اپلیکیشن تعریف کنید و شعبه پیش‌فرض را با `config('accounting.branch.default_id')` تنظیم کنید.

### ۱.۲ روابط بین جداول

| جدول مبدا | جدول مقصد | نوع رابطه | فیلد کلید خارجی |
|-----------|-----------|-----------|-----------------|
| accounts | accounts | Self-referential | parent_id |
| accounts | (شعبه اختیاری) | Many to One | branch_id |
| documents | fiscal_years | Many to One | fiscal_year_id |
| documents | (شعبه اختیاری) | Many to One | branch_id |
| document_items | documents | Many to One | document_id |
| document_items | accounts | Many to One | account_id |
| document_items | cost_centers | Many to One | cost_center_id |
| document_logs | documents | Many to One | document_id |

---

## ۲. فیلد branch_id (شعبه)

### ۲.۱ شرح

پکیج جدول شعبه را تعریف نمی‌کند. در جداول **accounts** و **documents** فقط فیلد **branch_id** (unsigned bigint, nullable) وجود دارد. مقدار این فیلد می‌تواند:

- **null** باشد (سند/حساب بدون تفکیک شعبه)، یا
- شناسه عددی شعبه باشد (مثلاً از `config('accounting.branch.default_id')` یا جدول/مدل Branch اپلیکیشن).

در صورت داشتن جدول شعبه در اپ، می‌توانید در مدل‌های پکیج رابطهٔ `branch()` را با `config('accounting.branch.model')` به آن متصل کنید.

---

## ۳. جدول fiscal_years (سال‌های مالی)

### ۳.۱ شرح

نگهداری اطلاعات دوره‌های مالی. هر سند باید در یک سال مالی ثبت شود.

### ۳.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| title | varchar(100) | ❌ | - | عنوان سال مالی |
| start_date | date | ❌ | - | تاریخ شروع |
| end_date | date | ❌ | - | تاریخ پایان |
| status | enum | ❌ | draft | وضعیت (draft, active, closed) |
| is_current | boolean | ❌ | false | سال مالی جاری |
| opening_done | boolean | ❌ | false | افتتاحیه انجام شده |
| opened_at | timestamp | ✅ | null | زمان افتتاح |
| closed_at | timestamp | ✅ | null | زمان بستن |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |

### ۳.۳ مقادیر Enum برای status

| مقدار | شرح |
|-------|-----|
| draft | پیش‌نویس - در حال تنظیم |
| active | فعال - قابل ثبت سند |
| closed | بسته - فقط قابل گزارش‌گیری |

### ۳.۴ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| fiscal_years_dates_unique | start_date, end_date | Unique | یکتایی بازه |
| fiscal_years_status_index | status | Index | فیلتر وضعیت |
| fiscal_years_is_current_index | is_current | Index | یافتن سال جاری |

### ۳.۵ نمونه داده

| id | title | start_date | end_date | status | is_current |
|----|-------|------------|----------|--------|------------|
| 1 | سال مالی ۱۴۰۲ | 1402-01-01 | 1402-12-29 | closed | false |
| 2 | سال مالی ۱۴۰۳ | 1403-01-01 | 1403-12-29 | active | true |

---

## ۴. جدول accounts (حساب‌ها)

### ۴.۱ شرح

هسته اصلی سیستم حسابداری. نگهداری درخت حساب‌ها با ۴ سطح.

### ۴.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| parent_id | bigint unsigned | ✅ | null | شناسه والد |
| branch_id | bigint unsigned | ✅ | null | شناسه شعبه |
| code | varchar(20) | ❌ | - | کد حساب |
| title | varchar(255) | ❌ | - | عنوان حساب |
| description | varchar(500) | ✅ | null | توضیحات |
| level | tinyint unsigned | ❌ | 0 | سطح (0-3) |
| type | enum | ❌ | - | نوع حساب |
| nature | enum | ❌ | - | ماهیت حساب |
| is_active | boolean | ❌ | true | وضعیت فعال |
| is_system | boolean | ❌ | false | حساب سیستمی |
| allow_direct_posting | boolean | ❌ | true | قابل ثبت مستقیم |
| entity_type | varchar(50) | ✅ | null | نوع موجودیت |
| entity_id | bigint unsigned | ✅ | null | شناسه موجودیت |
| cached_balance | decimal(15,2) | ❌ | 0.00 | مانده کش شده |
| balance_updated_at | timestamp | ✅ | null | زمان بروزرسانی مانده |
| meta | json | ✅ | null | اطلاعات اضافی |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |
| deleted_at | timestamp | ✅ | null | زمان حذف نرم |

### ۴.۳ مقادیر Enum برای type

| مقدار | شرح فارسی | ماهیت پیش‌فرض |
|-------|-----------|---------------|
| asset | دارایی | debit |
| liability | بدهی | credit |
| equity | سرمایه | credit |
| income | درآمد | credit |
| expense | هزینه | debit |

### ۴.۴ مقادیر Enum برای nature

| مقدار | شرح فارسی | علامت |
|-------|-----------|-------|
| debit | بدهکار | +1 |
| credit | بستانکار | -1 |

### ۴.۵ مقادیر level

| مقدار | نام | شرح |
|-------|-----|-----|
| 0 | Group | گروه - بالاترین سطح |
| 1 | Main | کل - زیرمجموعه گروه |
| 2 | Subsidiary | معین - زیرمجموعه کل |
| 3 | Detail | تفصیلی - قابل ثبت سند |

### ۴.۶ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| accounts_code_unique | code | Unique | یکتایی کد |
| accounts_parent_id_foreign | parent_id | Foreign | رابطه والد |
| accounts_branch_id_foreign | branch_id | Foreign | رابطه شعبه |
| accounts_entity_index | entity_type, entity_id | Index | جستجوی موجودیت |
| accounts_level_index | level | Index | فیلتر سطح |
| accounts_type_index | type | Index | فیلتر نوع |
| accounts_is_active_index | is_active | Index | فیلتر فعال |

### ۴.۷ محدودیت‌ها

| نام | نوع | شرح |
|-----|-----|-----|
| accounts_parent_id_foreign | Foreign Key | ON DELETE SET NULL |
| accounts_branch_id_foreign | Foreign Key | ON DELETE SET NULL |

### ۴.۸ نمونه داده

| id | parent_id | code | title | level | type | nature |
|----|-----------|------|-------|-------|------|--------|
| 1 | null | 1 | دارایی‌ها | 0 | asset | debit |
| 2 | 1 | 11 | دارایی جاری | 1 | asset | debit |
| 3 | 2 | 1101 | موجودی نقد | 2 | asset | debit |
| 4 | 3 | 110101 | صندوق مرکزی | 3 | asset | debit |
| 5 | 3 | 110102 | صندوق شعبه تهران | 3 | asset | debit |

---

## ۵. جدول cost_centers (مراکز هزینه)

### ۵.۱ شرح

نگهداری مراکز هزینه برای تفکیک هزینه‌ها و درآمدها بر اساس پروژه/بخش.

### ۵.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| code | varchar(20) | ❌ | - | کد مرکز هزینه |
| title | varchar(100) | ❌ | - | عنوان |
| description | varchar(255) | ✅ | null | توضیحات |
| is_active | boolean | ❌ | true | وضعیت فعال |
| meta | json | ✅ | null | اطلاعات اضافی |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |

### ۵.۳ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| cost_centers_code_unique | code | Unique | یکتایی کد |
| cost_centers_is_active_index | is_active | Index | فیلتر فعال |

### ۵.۴ نمونه داده

| id | code | title | is_active |
|----|------|-------|-----------|
| 1 | CC001 | پروژه توسعه اپ موبایل | true |
| 2 | CC002 | پروژه طراحی وب‌سایت | true |
| 3 | CC003 | واحد اداری | true |

---

## ۶. جدول documents (اسناد)

### ۶.۱ شرح

نگهداری اسناد حسابداری. هر رویداد مالی یک سند تولید می‌کند.

### ۶.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| fiscal_year_id | bigint unsigned | ❌ | - | شناسه سال مالی |
| branch_id | bigint unsigned | ✅ | null | شناسه شعبه |
| number | bigint unsigned | ❌ | - | شماره سند در سال |
| reference | varchar(50) | ✅ | null | شماره مرجع خارجی |
| date | date | ❌ | - | تاریخ سند |
| type | varchar(50) | ❌ | - | نوع سند |
| status | enum | ❌ | draft | وضعیت سند |
| description | varchar(500) | ✅ | null | توضیحات |
| notes | text | ✅ | null | یادداشت‌ها |
| source_type | varchar(50) | ✅ | null | نوع منبع |
| source_id | bigint unsigned | ✅ | null | شناسه منبع |
| posted_at | timestamp | ✅ | null | زمان ثبت قطعی |
| created_by | bigint unsigned | ✅ | null | ایجادکننده |
| approved_by | bigint unsigned | ✅ | null | تأییدکننده |
| posted_by | bigint unsigned | ✅ | null | ثبت‌کننده قطعی |
| meta | json | ✅ | null | اطلاعات اضافی |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |
| deleted_at | timestamp | ✅ | null | زمان حذف نرم |

### ۶.۳ مقادیر Enum برای status

| مقدار | شرح فارسی | قابل ویرایش | اثر بر مانده |
|-------|-----------|-------------|--------------|
| draft | پیش‌نویس | ✅ | ❌ |
| pending | در انتظار تأیید | ✅ | ❌ |
| approved | تأیید شده | ⚠️ محدود | ❌ |
| posted | ثبت شده | ❌ | ✅ |
| voided | باطل شده | ❌ | ❌ |

### ۶.۴ مقادیر رایج type

| مقدار | شرح فارسی |
|-------|-----------|
| sale | فروش |
| purchase | خرید |
| receipt | دریافت |
| payment | پرداخت |
| transfer | انتقال |
| opening | افتتاحیه |
| closing | اختتامیه |
| adjustment | تعدیل |

### ۶.۵ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| documents_number_unique | fiscal_year_id, number | Unique | یکتایی شماره در سال |
| documents_fiscal_year_id_foreign | fiscal_year_id | Foreign | رابطه سال مالی |
| documents_branch_id_foreign | branch_id | Foreign | رابطه شعبه |
| documents_date_index | date | Index | فیلتر تاریخ |
| documents_type_index | type | Index | فیلتر نوع |
| documents_status_index | status | Index | فیلتر وضعیت |
| documents_source_index | source_type, source_id | Index | جستجوی منبع |

### ۶.۶ محدودیت‌ها

| نام | نوع | شرح |
|-----|-----|-----|
| documents_fiscal_year_id_foreign | Foreign Key | ON DELETE RESTRICT |
| documents_branch_id_foreign | Foreign Key | ON DELETE SET NULL |

### ۶.۷ نمونه داده

| id | fiscal_year_id | number | date | type | status |
|----|----------------|--------|------|------|--------|
| 1 | 2 | 1 | 1403-01-15 | opening | posted |
| 2 | 2 | 2 | 1403-01-20 | sale | posted |
| 3 | 2 | 3 | 1403-01-22 | receipt | posted |
| 4 | 2 | 4 | 1403-01-25 | purchase | draft |

---

## ۷. جدول document_items (آیتم‌های سند)

### ۷.۱ شرح

نگهداری ردیف‌های بدهکار و بستانکار هر سند.

### ۷.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| document_id | bigint unsigned | ❌ | - | شناسه سند |
| account_id | bigint unsigned | ❌ | - | شناسه حساب |
| cost_center_id | bigint unsigned | ✅ | null | شناسه مرکز هزینه |
| amount | decimal(15,2) | ❌ | - | مبلغ |
| sign | tinyint | ❌ | - | علامت (+1 یا -1) |
| debit | decimal(15,2) | ❌ | 0.00 | مبلغ بدهکار |
| credit | decimal(15,2) | ❌ | 0.00 | مبلغ بستانکار |
| description | varchar(255) | ✅ | null | توضیح ردیف |
| order | smallint unsigned | ❌ | 0 | ترتیب نمایش |
| meta | json | ✅ | null | اطلاعات اضافی |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |

### ۷.۳ توضیح فیلدهای amount, sign, debit, credit

| فیلد | محاسبه | کاربرد |
|------|--------|--------|
| amount | مقدار مطلق مبلغ | ذخیره‌سازی اصلی |
| sign | +1 برای بدهکار، -1 برای بستانکار | محاسبه مانده |
| debit | اگر sign=+1 آنگاه amount وگرنه 0 | نمایش در گزارش |
| credit | اگر sign=-1 آنگاه amount وگرنه 0 | نمایش در گزارش |

### ۷.۴ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| document_items_document_id_foreign | document_id | Foreign | رابطه سند |
| document_items_account_id_foreign | account_id | Foreign | رابطه حساب |
| document_items_cost_center_id_foreign | cost_center_id | Foreign | رابطه مرکز هزینه |
| document_items_order_index | document_id, order | Index | ترتیب آیتم‌ها |

### ۷.۵ محدودیت‌ها

| نام | نوع | شرح |
|-----|-----|-----|
| document_items_document_id_foreign | Foreign Key | ON DELETE CASCADE |
| document_items_account_id_foreign | Foreign Key | ON DELETE RESTRICT |
| document_items_cost_center_id_foreign | Foreign Key | ON DELETE SET NULL |

### ۷.۶ نمونه داده

سند فروش نقدی به مبلغ ۱,۰۰۰,۰۰۰:

| id | document_id | account_id | amount | sign | debit | credit | description |
|----|-------------|------------|--------|------|-------|--------|-------------|
| 1 | 2 | 4 | 1000000 | +1 | 1000000 | 0 | دریافت نقدی |
| 2 | 2 | 20 | 1000000 | -1 | 0 | 1000000 | درآمد فروش |

---

## ۸. جدول document_logs (لاگ تغییرات)

### ۸.۱ شرح

ثبت تمام تغییرات اسناد برای Audit Trail.

### ۸.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| document_id | bigint unsigned | ❌ | - | شناسه سند |
| user_id | bigint unsigned | ✅ | null | شناسه کاربر |
| action | enum | ❌ | - | نوع عملیات |
| description | varchar(255) | ✅ | null | توضیح عملیات |
| old_values | json | ✅ | null | مقادیر قبلی |
| new_values | json | ✅ | null | مقادیر جدید |
| ip_address | varchar(45) | ✅ | null | آدرس IP |
| user_agent | varchar(255) | ✅ | null | مشخصات مرورگر |
| created_at | timestamp | ❌ | - | زمان عملیات |

### ۸.۳ مقادیر Enum برای action

| مقدار | شرح فارسی |
|-------|-----------|
| created | ایجاد سند |
| updated | ویرایش سند |
| submitted | ارسال برای تأیید |
| approved | تأیید سند |
| rejected | رد سند |
| posted | ثبت قطعی |
| voided | ابطال |
| restored | بازیابی |

### ۸.۴ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| document_logs_document_id_foreign | document_id | Foreign | رابطه سند |
| document_logs_user_id_index | user_id | Index | فیلتر کاربر |
| document_logs_action_index | action | Index | فیلتر عملیات |
| document_logs_created_at_index | created_at | Index | فیلتر زمان |

### ۸.۵ محدودیت‌ها

| نام | نوع | شرح |
|-----|-----|-----|
| document_logs_document_id_foreign | Foreign Key | ON DELETE CASCADE |

### ۸.۶ نمونه داده

| id | document_id | user_id | action | description | created_at |
|----|-------------|---------|--------|-------------|------------|
| 1 | 2 | 1 | created | سند شماره ۲ ایجاد شد | 1403-01-20 10:30:00 |
| 2 | 2 | 1 | submitted | ارسال برای تأیید | 1403-01-20 10:35:00 |
| 3 | 2 | 2 | approved | تأیید توسط مدیر مالی | 1403-01-20 11:00:00 |
| 4 | 2 | 2 | posted | ثبت قطعی | 1403-01-20 11:05:00 |

---

## ۹. جدول account_balances (خلاصه مانده - اختیاری)

### ۹.۱ شرح

جدول کمکی برای نگهداری خلاصه مانده حساب‌ها در هر سال مالی. استفاده از این جدول برای Performance در پروژه‌های بزرگ توصیه می‌شود.

### ۹.۲ فیلدها

| فیلد | نوع | Null | پیش‌فرض | شرح |
|------|-----|------|---------|-----|
| id | bigint unsigned | ❌ | auto | شناسه یکتا |
| account_id | bigint unsigned | ❌ | - | شناسه حساب |
| fiscal_year_id | bigint unsigned | ❌ | - | شناسه سال مالی |
| opening_debit | decimal(15,2) | ❌ | 0.00 | مانده افتتاحیه بدهکار |
| opening_credit | decimal(15,2) | ❌ | 0.00 | مانده افتتاحیه بستانکار |
| period_debit | decimal(15,2) | ❌ | 0.00 | گردش بدهکار دوره |
| period_credit | decimal(15,2) | ❌ | 0.00 | گردش بستانکار دوره |
| closing_balance | decimal(15,2) | ❌ | 0.00 | مانده پایانی |
| calculated_at | timestamp | ✅ | null | زمان محاسبه |
| created_at | timestamp | ✅ | null | زمان ایجاد |
| updated_at | timestamp | ✅ | null | زمان بروزرسانی |

### ۹.۳ ایندکس‌ها

| نام | فیلد(ها) | نوع | شرح |
|-----|----------|-----|-----|
| PRIMARY | id | Primary | کلید اصلی |
| account_balances_unique | account_id, fiscal_year_id | Unique | یکتایی ترکیب |
| account_balances_account_id_foreign | account_id | Foreign | رابطه حساب |
| account_balances_fiscal_year_id_foreign | fiscal_year_id | Foreign | رابطه سال مالی |

---

## ۱۰. فرمول‌های محاسباتی

### ۱۰.۱ محاسبه مانده حساب

```
مانده = SUM(amount × sign) از document_items
     WHERE document.status = 'posted'
```

### ۱۰.۲ بررسی بالانس سند

```
بالانس = SUM(amount × sign) از document_items سند
اگر بالانس = 0 → سند متعادل است
```

### ۱۰.۳ محاسبه مانده طبیعی

```
اگر nature = 'debit':
    مانده_طبیعی = مانده
وگرنه:
    مانده_طبیعی = -مانده
```

---

## ۱۱. نکات مهم

### ۱۱.۱ Soft Delete

جداول زیر از Soft Delete استفاده می‌کنند:

| جدول | فیلد |
|------|------|
| accounts | deleted_at |
| documents | deleted_at |

### ۱۱.۲ فیلد meta

تمام جداول اصلی یک فیلد `meta` از نوع JSON دارند که برای ذخیره اطلاعات اضافی بدون نیاز به تغییر Schema استفاده می‌شود.

**مثال کاربرد:**

| جدول | محتوای meta |
|------|-------------|
| accounts | تنظیمات خاص حساب |
| documents | اطلاعات فاکتور، شماره پیگیری |
| branches | آدرس، تلفن، مدیر شعبه |

### ۱۱.۳ ارتباط با جدول users

فیلدهای `created_by`، `approved_by` و `posted_by` در جدول documents به جدول `users` پروژه اشاره می‌کنند. این ارتباط از طریق Config قابل تنظیم است.

### ۱۱.۴ Precision مبالغ

تمام فیلدهای مبلغ از نوع `decimal(15,2)` هستند:

| ویژگی | مقدار |
|-------|-------|
| حداکثر رقم | ۱۵ رقم |
| ارقام اعشار | ۲ رقم |
| حداکثر مقدار | ۹,۹۹۹,۹۹۹,۹۹۹,۹۹۹.۹۹ |

---

## ۱۲. ترتیب ایجاد جداول (Migration Order)

| ترتیب | جدول | وابستگی |
|-------|------|---------|
| ۱ | branches | - |
| ۲ | fiscal_years | - |
| ۳ | accounts | branches |
| ۴ | cost_centers | - |
| ۵ | documents | fiscal_years, branches |
| ۶ | document_items | documents, accounts, cost_centers |
| ۷ | document_logs | documents |
| ۸ | account_balances (اختیاری) | accounts, fiscal_years |

---

[→ ادامه: نصب (05-installation.md)](05-installation.md)

[← بازگشت: معماری فنی (03-architecture.md)](03-architecture.md)

[⌂ فهرست (00-index.md)](00-index.md)