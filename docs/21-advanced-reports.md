# گزارش‌های پیشرفته

این سند قرارداد گزارش‌های اضافه‌شده در ۱۳.۱۱.۰ است. محاسبات فقط از دفتر ثبت‌شده (`document_items` ⋈ `documents`) خوانده می‌شوند، نه از `cached_balance`.

مرجع مشترک فیلتر و صفحه‌بندی: `LedgerReportFilters` و `ReportPagination`.
پایه query: `LedgerQuery` — منطق posted/void/افتتاحیه اینجا تکرار نشده است.

## ماتریس قابلیت

| Report | FY | Period | Date | Branch | All Branches | Cost Center | Account | Pagination |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Trial Balance | ✓ | ✓ | ✓ | ✓ | ✓ (بدون فیلتر شعبه) | ✓ | ✓ | account (موجود: offset روی خلاصه) |
| General Ledger | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | entry / account summary |
| Account Turnover | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | account |
| Journal Book | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | document (پیش‌فرض cursor) |
| Daily Journal | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | day / group |
| Period Closing | ✓ | ✓ (الزامی) | از دوره | ✓ | ✓ | — | جزئیات موقت | detail rows |
| Comparative | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | aligned account |
| AR/AP Aging | — | — | as-of | ✓ (قرارداد) | ✓ (قرارداد) | شرطی | ✓ (قرارداد) | party — **فقط با provider** |

`all_branches=true` یعنی همه شعبه‌های داخل scope حسابداری فراخوان، نه همه tenantهای دیتابیس. پکیج جدول شرکت/tenant ندارد؛ isolation ساختاری شعبه حفظ می‌شود و authorization با میزبان است.

---

## فیلتر مشترک (`LedgerReportFilters`)

### تاریخ

- `from_date` / `to_date` مرز inclusive هستند.
- `from_date <= to_date` الزامی است.
- اگر `fiscal_year_id` باشد، هر دو تاریخ باید داخل همان سال باشند.
- اگر `accounting_period_id` باشد و تاریخ صریح نباشد، بازه مؤثر = `start_date`/`end_date` دوره است.
- تاریخ صریح خارج از دوره **رد** می‌شود؛ هرگز ساکت بریده نمی‌شود.

### سال مالی و دوره

- گزارش سال بسته‌شده مجاز است.
- دوره باید متعلق به سال انتخاب‌شده باشد.
- گزارش به سال جاری محدود نیست.

### شعبه (یک حالت در هر درخواست)

| حالت | ورودی |
| --- | --- |
| تک شعبه | `branch_id` |
| چند شعبه | `branch_ids[]` |
| همه شعبه‌های scope | `all_branches=true` |
| پیش‌فرض بسته | هیچ‌کدام — همان قرارداد `LedgerQuery` (بدون predicate شعبه) |

ترکیب‌های مبهم (`branch_id`+`branch_ids`، `branch_id`+`all_branches`، `branch_ids`+`all_branches`) رد می‌شوند.

`branch_id=null` به all-branches تبدیل **نمی‌شود**.

`include_branch_breakdown=true` جمع هر شعبه را جدا برمی‌گرداند؛ ردیف دفتر برای جمع کلاش تکراری نمی‌شود.

### سایر فیلترها (جایی که معنای حسابداری دارند)

`account_id` / `account_ids[]` / `account_type` / `include_children` / `rollup_hierarchy` / `leaf_only` /
`cost_center_id` / `cost_center_ids[]` / `document_type` / `document_types[]` /
`document_number_from` / `document_number_to` / `search` / `min_amount` / `max_amount` /
`include_zero_activity` / `include_zero_balance` / `posted_only` / `mode` / `sort_by` / `sort_direction`

`search` فقط روی این فیلدها: شماره سند (exact وقتی عددی است)، شرح سند، reference، کد حساب، نام حساب.

### وضعیت مالی

- `mode=financial` (پیش‌فرض): فقط `posted`. draft کنار است. voided کنار است چون status دیگر posted نیست. reversal سند posted جدا است و همراه اصل در دفتر می‌ماند.
- `mode=audit`: ردیف‌های draft/voided در دفتر روزنامه دیده می‌شوند. **جمع مالی رسمی همچنان posted-only است.**

---

## صفحه‌بندی

پیش‌فرض گزارش‌های ترتیبی بزرگ: **cursor/keyset**.
`COUNT(*)` فقط برای offset یا وقتی `include_total=true`.

`per_page`: پیش‌فرض ۵۰، حداقل ۱، حداکثر ۲۰۰ (`accounting.reports.per_page_*`).

پاسخ:

```json
{
  "meta": { "report": "account_turnover", "generated_at": "...", "filters": {}, "branch_scope": {} },
  "summary": {},
  "page_totals": {},
  "report_totals": {},
  "data": [],
  "pagination": { "mode": "cursor", "per_page": 50, "next_cursor": "...", "has_more": true }
}
```

`report_totals` همیشه کل فیلتر است، نه صفحه جاری.

ثبات: cursor روی دفتر زنده ممکن است ردیف تازه‌درج‌شده را ببیند. snapshot isolation پیاده نشده. دورهٔ بسته‌شده معمولاً پایدار است.

---

## ۱. گردش حساب (`accountTurnover`)

### هدف

مانده اول دوره، گردش بدهکار/بستانکار، حرکت خالص و مانده پایان هر حساب در بازه.

### منبع حقیقت

`LedgerQuery::openingDebitCreditByAccount()` + `periodActivityByAccount()`.
افتتاحیه گزارش = مانده مؤثر مالی **قبل از** `from_date`، نه سند `type=opening` به‌تنهایی.
اگر query به سال مالی scope شده باشد، افتتاحیه همان سال است (سال قبل نشت نمی‌کند).

### خروجی هر ردیف

`account_id`, `account_code`, `account_name`, `account_type`, `normal_balance`,
`opening_debit/credit/balance`, `period_debit_turnover`, `period_credit_turnover`, `net_movement`,
`closing_debit/credit/balance`, `transaction_count`, `document_count`

### سلسله‌مراتب

`rollup_hierarchy=true`: جمع والد = جمع نوادگان برگ، بدون شمارش دوباره فرزند در والد.

### صفحه‌بندی

واحد صفحه = یک حساب کامل. پیش‌فرض `account_code ASC, account_id ASC`.
تجمیع در SQL است؛ chart حساب‌ها (نه میلیون‌ها خط دفتر) برای sortهای محاسبه‌شده مرتب می‌شود.

### مثال

```php
Accounting::report()->accountTurnover([
    'from_date' => '2026-01-01',
    'to_date' => '2026-01-31',
    'branch_id' => 1,
    'per_page' => 50,
]);
```

برای تراز کامل دفتر: `total_period_debit == total_period_credit` با مقایسه `Amount`.

---

## ۲. دفتر روزنامه (`journalBook`)

### هدف

ثبت زمانی اسناد با تمام خطوط بدهکار/بستانکار و قابلیت ردیابی.

### صفحه‌بندی

پیش‌فرض `pagination_granularity=document` — سند بین صفحات نصف نمی‌شود.
الگو: ۱) صفحه هدر سند ۲) همه خطوط همان idها در یک query ۳) گروه‌بندی فقط برای صفحه جاری.

ترتیب: `document_date, document_number, document_id`.

### جمع‌ها

`filtered_document_count`, `filtered_line_count`, `total_debit`, `total_credit`.
روزنامه مالی رسمی: `total_debit == total_credit`.

---

## ۳. خلاصه روزانه (`dailyJournal`)

### هدف

فعالیت هر روز تقویمی، نه تک‌تک اسناد.

`group_by`: `day` | `day_branch` | `day_account` | `day_document_type`

با `all_branches` + `day`: جمع روزانه. با `day_branch`: یک ردیف برای هر روز/شعبه.

ترتیب پیش‌فرض: `date DESC` (+ `branch_id ASC` در حالت day_branch).

برای دفتر معتبر: `net_difference = 0`.

---

## ۴. آمادگی بستن دوره (`periodClosing`)

### هدف

تشخیصی و فقط‌خواندنی. **هیچ دوره‌ای را نمی‌بندد** و ژورنال نمی‌سازد.

`accounting_period_id` الزامی است.

### آمادگی

`AccountingPeriodService::close()` امروز فقط OPEN بودن دوره را لازم دارد.
این گزارش سخت‌گیرانه‌تر است: اسناد ثبت‌نشده blocker آمادگی‌اند حتی اگر `close()` هنوز آن‌ها را رد نکند.

| کد | شدت | معنا |
| --- | --- | --- |
| `PERIOD_EXISTS` | blocker | دوره وجود دارد |
| `PERIOD_FISCAL_YEAR_MATCH` | blocker | دوره متعلق به سال است |
| `PERIOD_ALREADY_CLOSED` | blocker | دوره قبلاً بسته شده |
| `PERIOD_CLOSABLE` | blocker | دوره OPEN است |
| `DEBIT_EQUALS_CREDIT` | blocker | جمع posted متعادل است |
| `UNPOSTED_DOCUMENTS` | blocker | draft/pending/approved باقی است |
| `EXISTING_CLOSING_JOURNAL` | warning | سند `type=closing` موجود است |
| `DUPLICATE_CLOSING_JOURNAL` | warning | بیش از یک سند بستن |
| `RETAINED_EARNINGS_AVAILABLE` | warning | برای بستن سود و زیان سال، نه close دوره |
| `OPENING_INCOMPLETE` | warning | `opening_done` نیست |
| `SYSTEM_ACCOUNTS_AVAILABLE` | warning | کلید retained earnings تنظیم شده |
| `TEMPORARY_ACCOUNTS_REMAINING` | warning | مانده حساب‌های موقت |
| `BRANCH_NOT_READY` | blocker | در scope چندشعبه، یک شعبه آماده نیست |

اگر یک شعبه blocker داشته باشد، `all_branches.ready_to_close = false`.
بستن دوره در پکیج per-branch مستقل نیست.

---

## ۵. مقایسه دوره‌ها (`comparePeriods`)

دو scope صریح `current` و `comparison`. ابتدا Account Turnover هر دو ساخته و بر `account_id` هم‌تراز می‌شود، **سپس** صفحه می‌شود. حسابی که فقط در یک دوره باشد با صفر طرف مقابل می‌آید.

درصد واریانس:

- comparison ≠ 0 → `((current - comparison) / comparison) * 100`
- هر دو صفر → `0` و `unchanged`
- comparison = 0 و current ≠ 0 → `null` و `new`
- current = 0 و comparison ≠ 0 → `cleared`

تقسیم بر صفر انجام نمی‌شود.

---

## ۶. سن مطالبات/بدهی‌ها

**پیاده‌سازی بومی وجود ندارد.** دفتر کل `due_date`، طرف حساب، مبلغ تسویه‌شده یا open item ندارد. ساخت aging از نام حساب یا شرح سند گمراه‌کننده است.

قرارداد توسعه: `Karnoweb\Accounting\Reporting\Aging\AgingSourceProvider`

میزبان باید برای هر open item بدهد: `party_id/code/name`, `due_date`, `original_amount`, `settled_amount`, `outstanding_amount`, `source_type/id`.
آیتم تسویه‌شده کامل باید حذف شود.

```php
$this->app->bind(AgingSourceProvider::class, HostOpenItemProvider::class);
Accounting::report()->receivableAging(['as_of_date' => '2026-01-31']);
```

بدون bind: `AgingUnavailableException`.

سطل‌ها وقتی provider هست: current / 1-30 / 31-60 / 61-90 / 91-120 / 120+.

---

## API

```php
Accounting::report()->accountTurnover($filters);
Accounting::report()->journalBook($filters);
Accounting::report()->dailyJournal($filters);
Accounting::report()->periodClosing($filters);
Accounting::report()->comparePeriods($current, $comparison);
Accounting::report()->receivableAging($filters); // فقط با provider
Accounting::report()->payableAging($filters);
```

`$filters` آرایه یا `LedgerReportFilters` است. صفحه‌بندی در همان آرایه یا آرگومان دوم `ReportPagination`.

Trial Balance و General Ledger روی `LedgerQuery` باقی مانده‌اند و شکسته نشده‌اند.

---

## ایندکس‌ها

| ایندکس | دلیل |
| --- | --- |
| `documents (status, type, date)` | دفتر روزنامه / خلاصه روزانه با نوع سند |
| `document_items (cost_center_id, document_id)` | فیلتر مرکز هزینه و join به سند |

ایندکس‌های ۱۳.۲.۰ (`status+fy+date`, `status+date`, `branch+status+date`, `account_id+document_id`) همچنان مسیر اصلی ledger هستند.

---

## عملکرد

- تجمیع SUM/COUNT/GROUP BY در SQL است؛ خطوط خام دفتر برای جمع کل به PHP نمی‌آیند.
- دفتر روزنامه N+1 ندارد: خطوط صفحه در یک query.
- سلسله‌مراتب حساب یک‌بار load می‌شود (مثل `HierarchyRollup`).
- cache گزارش پیاده نشده؛ صحت به `cached_balance` وابسته نیست.

## محدودیت‌ها

- AR/AP native نیست.
- cursor روی دفتر در حال تغییر snapshot نیست.
- صورت جریان O/I/F هنوز نیست.
- PDF/Excel در هسته نیست؛ DTOها برای export بعدی آماده‌اند.
- `all_branches` مرز tenant را خودش enforce نمی‌کند.

## واژه‌نامه

| واژه | معنی |
| --- | --- |
| Ledger | دفتر ثبت‌شده posted |
| FY | سال مالی |
| Period | دوره حسابداری داخل یک FY |
| Cursor pagination | صفحه بعدی با کلید یکتای آخرین ردیف، بدون COUNT |
| Open item | طلب/بدهی تسویه‌نشده در زیرسیستم عملیاتی |
| Aging | توزیع مانده باز بر حسب دیرکرد نسبت به due_date |
| Rollup | جمع والد از نوادگان برگ بدون شمارش دوباره |
