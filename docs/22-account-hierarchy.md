# سلسله‌مراتب و فهرست حساب‌ها

قرارداد عمومی چهار سطح است. پکیج نام دامنه مثل Group / Kol / Moein / Tafsili را در API وارد نمی‌کند.

| سطح عمومی | `accounts.level` ذخیره‌شده | نقش |
|-----------|----------------------------|-----|
| Level 1 | 0 | ریشه |
| Level 2 | 1 | |
| Level 3 | 2 | والد مستقیم Level 4 |
| Level 4 | 3 (سطح ثبت پیش‌فرض) | حساب قابل‌ثبت؛ ممکن است صدها هزار یا میلیون ردیف باشد |

`AccountService::search(['level' => …])` همچنان سطح **ذخیره‌شده** را می‌گیرد (سازگاری عقب‌رو).
`hierarchy()` و `paginate()` و فیلتر `level` در گردش حساب سطح **عمومی ۱–۴** هستند.

---

## درخت (`/accounts/hierarchy`)

```php
Accounting::account()->hierarchy();                 // max_level=3
Accounting::account()->hierarchy(['max_level' => 1]);
Accounting::account()->hierarchy(['max_level' => 2]);
Accounting::account()->hierarchy(['max_level' => 3]);
```

`max_level=3` یعنی:

```text
Level 1
 └── Level 2
      └── Level 3
```

Level 4 هرگز داخل درخت نیست. `max_level > 3` رد می‌شود.

هر گره:

`id`, `code`, `name` (از `title`), `level` (عمومی), `parent_id`, `is_active`,
`has_children`, `children_count`

روی Level 3 علاوه بر آن: `level_4_count`, `has_level_4` — از `COUNT` بدون بارگذاری ردیف‌های Level 4.

فیلتر شعبه همان `BranchScope` است: دقیقاً یکی از `branch_id` / `branch_ids[]` / `all_branches=true`.
`branch_id=null` به معنی همه شعبه‌ها نیست. حساب مشترک (`accounts.branch_id` خالی) در scope تک‌شعبه/چندشعبه دیده می‌شود.

---

## فهرست صفحه‌بندی‌شده (`/accounts`)

```php
Accounting::account()->paginate(['level' => 1]);
Accounting::account()->paginate(['level' => 2, 'parent_id' => 10]);
Accounting::account()->paginate(['level' => 3, 'parent_id' => 25]);
Accounting::account()->paginate([
    'level' => 4,
    'parent_id' => 100,
    'cursor' => $cursor,
    'per_page' => 50,
    'search' => '11',
    'code' => '110101',
    'is_active' => true,
]);
```

همهٔ چهار سطح صفحه‌بندی دیتابیسی دارند. Level 4 با keyset:

```text
WHERE level = 3 AND parent_id = ? AND …
ORDER BY code, id
LIMIT per_page + 1
```

`search` فقط پیشوند `code` (`LIKE 'term%'`) و حاوی `title` است.
`code=` برابر دقیق و مناسب ایندکس است.

ایندکس پشتیبان: `(level, parent_id, code, id)`.

---

## گردش حساب سطح‌آگاه

```php
Accounting::report()->accountTurnover([
    'level' => 4,
    'parent_id' => 100,
    'from_date' => '2026-01-01',
    'to_date' => '2026-01-31',
    'branch_id' => 1,
    'cursor' => $cursor,
]);
```

Level 1–3: جمع گردش نوادگان سطح ثبت، بدون بار کردن کل Level 4 در PHP.
Level 4: صفحه از فهرست حساب + تجمیع دفتر فقط برای همان صفحه؛ `report_totals` از SQL روی **همه** حساب‌های منطبق است و با عوض شدن `cursor`/`per_page` عوض نمی‌شود.

`page_totals` فقط صفحه جاری است.

---

## HTTP اختیاری

پیش‌فرض خاموش است تا با route میزبان تداخل نکند.

```env
ACCOUNTING_ROUTES_ENABLED=true
ACCOUNTING_ROUTES_PREFIX=
```

| روش | مسیر |
|-----|------|
| GET | `/accounts/hierarchy` |
| GET | `/accounts` |

---

## واژه‌نامه

| اصطلاح | معنی |
|--------|------|
| Level 1–4 | شمارهٔ عمومی سلسله‌مراتب |
| stored level | ستون `accounts.level` (۰-پایه) |
| Level 4 | سطح ثبت پیش‌فرض؛ در درخت hierarchy نیست |
| keyset / cursor | صفحه‌بندی پایدار روی `(code, id)` |
| BranchScope | انتخاب انحصاری شعبه برای حساب و گزارش |
