# Appendix (ضمیمه فنی)

## Glossary (واژه‌نامه)

* **Account**: موجودیت حسابداری پایه
* **Detail Account**: تنها سطحی که ثبت مالی روی آن انجام می‌شود
* **Document**: سند حسابداری شامل چند ردیف
* **Document Item**: یک ردیف بدهکار یا بستانکار
* **Fiscal Year**: بازه مالی فعال
* **Branch**: شعبه عملیاتی مستقل
* **Cost Center**: بُعد تحلیلی اختیاری

---

## FAQ

### چرا حسابداری polymorphic نیست؟

برای جلوگیری از کوئری‌های پیچیده و گزارش‌گیری غیرقابل پیش‌بینی.
تمام ثبت‌ها باید به account ختم شوند.

### آیا می‌توان سند نامتوازن ثبت کرد؟

خیر. این اعتبارسنجی در Domain enforce شده است.

### آیا حذف سند وجود دارد؟

خیر. فقط void / reverse.

---

## Design Decisions (ADR)

### Account-Centric Design

تمام سیستم حول account طراحی شده تا گزارش‌ها ساده بمانند.

### No Stored Balance

هیچ مانده‌ای ذخیره نمی‌شود؛ همه چیز محاسبه‌ای است.

### Trait-Based Integration

مدل‌های پروژه با Trait به سیستم وصل می‌شوند:

```php
use HasAccountingAccount;
```

---

## ارتباط با Facade

Facade اصلی:

```php
Accounting::document()
    ->for($model)
    ->addDebit($account, $amount)
    ->addCredit($account, $amount)
    ->post();
```

* Facade فقط orchestration
* لاجیک داخل Serviceها

---

## Changelog

### v1.0.0

* Core accounting
* Chart of accounts
* Document lifecycle

---

## Future Roadmap

* Period closing
* Budgeting
* Tax module
* Multi-currency

---

## نکات توسعه

* همیشه از Enum استفاده کن
* هیچ متن hardcode نکن
* Facade فقط برای اپلیکیشن است
* Domain باید framework-agnostic بماند
