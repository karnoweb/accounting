# ضمیمه

## واژه‌نامه

- **Account**: حساب مالی
- **Posting Level**: سطحی از درخت حساب که ثبت مستقیم روی آن مجاز است
- **Document**: هدر سند حسابداری
- **DocumentItem**: ردیف سند
- **Fiscal Year**: سال مالی (`draft` / `active` / `closed`)
- **Accounting Period**: دوره ثبت داخل سال مالی (`draft` / `open` / `closed`)؛ ثبت بدون دورهٔ باز رد می‌شود
- **Opening**: سند `type=opening` برای مانده ابتدای سال یا انتقال مانده
- **Closing**: سند `type=closing` برای بستن حساب‌های موقت به سود انباشته — با `FiscalYear::close()` فرق دارد
- **Reversal**: سند جدید `type=reversal` با مبالغ معکوس؛ اصل posted می‌ماند
- **Void**: اصل از posted به voided می‌رود و اثر دفتر حذف می‌شود؛ سند جدید ساخته نمی‌شود

## تفاوت مفاهیم مشابه

### `void` در برابر `reversal`

- `void`: سند اصلی `POSTED → VOIDED`؛ observer ماندهٔ کش را برمی‌گرداند؛ گزارش‌ها voided را نمی‌بینند. اگر reversal posted برای همان اصل وجود داشته باشد، void رد می‌شود. کلیدهای idempotency افتتاح/اختتام/برگشت پاک می‌شوند.
- `reversal`: اصل دست‌نخورده می‌ماند؛ سند جدید با علامت‌های معکوس. همان FY باید `active` باشد. روی opening/closing و وقتی closing posted در سال هست، رد می‌شود. کلید `reversal:{id}`. FY/شعبه قابل override نیستند.

### `FiscalYear::close()` در برابر `ClosingService::closeProfitAndLoss()`

- `FiscalYear::close()`: فقط lifecycle year را می‌بندد
- `ClosingService::closeProfitAndLoss()`: سند اختتامیه سود و زیان می‌سازد

### `Account` در برابر `CostCenter`

- `Account`: موضوع مالی ثبت
- `CostCenter`: بُعد تحلیلی اختیاری روی ردیف

### `reference` در برابر `number`

- `number`: شماره داخلی سند در سال مالی
- `reference`: متن آزاد برای ارجاع بیرونی

### `branch_id` در برابر `account_id`

- `account_id`: حساب مالی ردیف
- `branch_id`: بُعد تفکیک عملیاتی سند یا حساب

## FAQ

### آیا پکیج ماژول بانک یا صندوق دارد؟

خیر. فقط می‌توانید برای این مفاهیم در اپلیکیشن خود حساب بسازید یا از حساب‌های سیستمی استفاده کنید.

### آیا می‌توان سند نامتعادل ثبت کرد؟

خیر. `DocumentService` آن را رد می‌کند.

### آیا می‌توان روی حساب گروه یا کل ثبت زد؟

خیر. فقط حساب قابل‌ثبت در `posting_level`.

### آیا سال مالی بسته قابل بازگشایی است؟

خیر. در کد فعلی `reopen()` وجود ندارد.

### آیا گزارش‌ها از `cached_balance` استفاده می‌کنند؟

خیر. گزارش‌های هسته‌ای از ledger می‌خوانند.

### آیا `source_type/source_id` یکتا هستند؟

خیر. برای جلوگیری از تکرار باید از `idempotency_key` استفاده کنید.

## تصمیم‌های معماری مهم

1. Detail-only posting
2. Ledger-first reporting
3. Journal-free fiscal-year close
4. Deterministic idempotency for opening/closing/reversal
5. Separation between package core and consumer domain
