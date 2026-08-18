# ضمیمه

## واژه‌نامه

- **Account**: حساب مالی
- **Posting Level**: سطحی از درخت حساب که ثبت مستقیم روی آن مجاز است
- **Document**: هدر سند حسابداری
- **DocumentItem**: ردیف سند
- **Fiscal Year**: تنها دوره مالی persisted در پکیج
- **Opening**: ثبت مانده ابتدای سال یا انتقال مانده
- **Closing**: بستن مانده حساب‌های موقت به سود انباشته
- **Reversal**: سند معکوس‌کننده سند posted
- **Void**: ابطال سند posted بدون ساخت سند جدید

## تفاوت مفاهیم مشابه

### `void` در برابر `reversal`

- `void`: سند از posted ledger خارج می‌شود
- `reversal`: سند اصلی می‌ماند و سند معکوس ساخته می‌شود

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
