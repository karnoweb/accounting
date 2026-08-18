# امنیت و محدودیت‌های حفاظتی

این فایل روی آن دسته از رفتارهایی تمرکز دارد که در هسته پکیج برای جلوگیری از خراب شدن دفتر حسابداری enforce می‌شوند.

## ۱. Audit Trail

پکیج برای اسناد، لاگ تغییرات نگه می‌دارد. این لاگ در `document_logs` ذخیره می‌شود.

### داده‌های ثبت‌شده

- `document_id`
- `user_id`
- `action`
- `description`
- `old_values`
- `new_values`
- `ip_address`
- `user_agent`
- `created_at`

### عملیات‌های audit

بر اساس `AuditAction`:

- `created`
- `updated`
- `submitted`
- `approved`
- `rejected`
- `posted`
- `voided`
- `restored`

نکته: وجود enum به معنی وجود تمام workflowها در سطح business process نیست؛ اما این actionها برای logging پشتیبانی شده‌اند.

## ۲. تغییرناپذیری سند ثبت‌شده

### قرارداد

سند `posted` یا `voided` دیگر قابل ویرایش معمولی نیست.

### enforcement

در `Document::booted()`:

- اگر وضعیت قبلی `posted` باشد، فقط تغییر به `voided` مجاز است
- اگر وضعیت قبلی `voided` باشد، هر update رد می‌شود
- حذف سند `posted` یا `voided` رد می‌شود

### نتیجه

این قاعده یکی از مهم‌ترین contractهای پکیج است و تغییر آن روی audit، گزارش‌ها و صحت ledger اثر مستقیم دارد.

## ۳. تغییرناپذیری ردیف سند

`DocumentItem` بعد از posted یا voided شدن سند:

- قابل update نیست
- قابل delete نیست

فیلدهای مالی مثل `account_id`, `amount`, `sign`, `cost_center_id`, `order` immutable می‌شوند.

## ۴. کنترل حساب قابل‌ثبت

پکیج اجازه ثبت روی هر حسابی را نمی‌دهد.

شرایط حساب قابل‌ثبت:

- فعال باشد
- `allow_direct_posting` داشته باشد
- در `posting_level` باشد
- فرزند نداشته باشد

اگر این شروط برقرار نباشد:

- `InactiveAccountException`
- یا `InvalidPostingAccountException`

پرتاب می‌شود.

## ۵. محافظت از حساب سیستمی

اگر `Account` دارای `is_system = true` باشد:

- تغییر `code` مجاز نیست
- تغییر `type` مجاز نیست
- تغییر `nature` مجاز نیست
- حذف مجاز نیست

این حفاظت برای جلوگیری از شکستن قرارداد سرویس‌هایی مثل `ClosingService` حیاتی است.

## ۶. کنترل یکتایی و idempotency

### شماره سند

در دیتابیس:

- `(fiscal_year_id, number)` unique است

در application:

- تخصیص شماره با `document_number_sequences` و `lockForUpdate()` انجام می‌شود

### `idempotency_key`

در دیتابیس:

- `documents.idempotency_key` unique است

در application:

- قبل از create نیز بررسی می‌شود
- برای retry امن کاربرد دارد

### کلیدهای deterministic

برخی سرویس‌ها از کلیدهای deterministic استفاده می‌کنند:

- `opening:{fyId}:branch:{id|none}`
- `closing:{fyId}:branch:{id|none}`
- `reversal:{originalId}`

## ۷. کنترل سال مالی

### چه چیزی enforce می‌شود؟

- هم‌پوشانی سال مالی
- فقط یک سال `active`
- ممنوع بودن ثبت در سال `closed`
- ممنوع بودن ثبت در سال `draft`
- تطابق تاریخ سند با بازه FY

### چه چیزی enforce نمی‌شود؟

- دوره مالی ماهانه
- lock فصلی
- بازگشایی سال بسته

## ۸. تفاوت `void` و `reversal` از نظر حفاظتی

### `void`

- سند اصلی را از posted ledger خارج می‌کند
- سند جدید نمی‌سازد
- اگر روی سندی برگشت posted وجود داشته باشد، `void` روی اصل رد می‌شود

### `reversal`

- سند اصلی را دست‌نخورده نگه می‌دارد
- سند جدید `type=reversal` می‌سازد
- فقط در همان FY active مجاز است
- اگر closing posted در همان FY وجود داشته باشد، رد می‌شود

## ۹. چه چیزهایی را دیتابیس enforce می‌کند؟

- unique بودن `(fiscal_year_id, number)`
- unique بودن `idempotency_key`
- unique بودن `(start_date, end_date)` برای FY
- unique بودن `(fiscal_year_id, branch_id)` در جدول sequence
- foreign keyهای اصلی بین documents/items/fiscal years

## ۱۰. چه چیزهایی را application enforce می‌کند؟

- تعادل سند
- حساب قابل‌ثبت
- تغییرناپذیری سند posted/voided
- تغییرناپذیری `DocumentItem`
- هم‌پوشانی غیردقیق سال‌های مالی
- فقط یک FY active
- قواعد افتتاحیه
- قواعد اختتامیه
- قواعد reversal

## ۱۱. محدودیت مهم

این پکیج **Authorization** اپلیکیشن شما را پیاده‌سازی نمی‌کند.

یعنی:

- مشخص نمی‌کند چه کسی حق ثبت سند دارد
- مشخص نمی‌کند چه کسی حق ابطال دارد
- نقش‌ها و permissionها را مدیریت نمی‌کند

این بخش باید در اپلیکیشن مصرف‌کننده پیاده شود.
