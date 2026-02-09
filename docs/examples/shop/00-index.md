# 00-index.md

## هدف این داکیومنت

این فایل مرجع اصلی سناریوهای حسابداری فروشگاهی است و به‌عنوان نقطه شروع تمام مثال‌ها استفاده می‌شود.
هر سناریو در یک فایل مستقل پیاده‌سازی شده و از این فایل ارجاع می‌گیرد.

---

## ساختار کلی داکیومنت‌ها

هر فایل مثال شامل بخش‌های زیر است:

1. تعریف سناریو و فرضیات
2. جدول فاکتور فروش
3. منطق محاسبات (عدد به عدد)
4. سند حسابداری (بدهکار / بستانکار)
5. شرط‌های فعال و غیرفعال

---

## سناریوهای فروش (Sales Scenarios)

### فروش نقدی

* [01-sale-cash-no-discount](01-sale-cash-no-discount.md)
* [02-sale-cash-product-discount](02-sale-cash-product-discount.md)
* [03-sale-cash-invoice-discount](03-sale-cash-invoice-discount.md)
* [04-sale-cash-product-and-invoice-discount](04-sale-cash-product-and-invoice-discount.md)
* [05-sale-cash-no-tax](05-sale-cash-no-tax.md)
* [06-sale-cash-with-tax](06-sale-cash-with-tax.md)
* [07-sale-cash-with-shipping-taxable](07-sale-cash-with-shipping-taxable.md)
* [08-sale-cash-with-shipping-non-taxable](08-sale-cash-with-shipping-non-taxable.md)

### فروش نسیه

* [09-sale-credit](09-sale-credit.md)
* [10-sale-credit-with-advance](10-sale-credit-with-advance.md)
* [11-sale-credit-multi-due](11-sale-credit-multi-due.md)
* [12-sale-credit-with-penalty](12-sale-credit-with-penalty.md)
* [13-credit-settlement-full](13-credit-settlement-full.md)
* [14-credit-settlement-partial](14-credit-settlement-partial.md)

### فروش ترکیبی

* [15-sale-mixed-cash-credit](15-sale-mixed-cash-credit.md)
* [16-sale-wallet](16-sale-wallet.md)
* [17-sale-credit-note](17-sale-credit-note.md)

---

## تخفیف‌ها

* [18-product-discount](18-product-discount.md)
* [19-invoice-discount](19-invoice-discount.md)
* [20-post-issue-discount](20-post-issue-discount.md)
* [21-campaign-discount](21-campaign-discount.md)
* [22-volume-discount](22-volume-discount.md)
* [23-customer-group-discount](23-customer-group-discount.md)

---

## مالیات

* [24-sale-taxable](24-sale-taxable.md)
* [25-sale-non-taxable](25-sale-non-taxable.md)
* [26-mixed-tax-items](26-mixed-tax-items.md)
* [27-multi-tax-rate](27-multi-tax-rate.md)
* [28-tax-adjustment](28-tax-adjustment.md)
* [29-tax-return](29-tax-return.md)

---

## باربری و هزینه‌ها

* [30-shipping-income](30-shipping-income.md)
* [31-shipping-expense](31-shipping-expense.md)
* [32-shipping-fixed](32-shipping-fixed.md)
* [33-shipping-variable](33-shipping-variable.md)
* [34-packaging-cost](34-packaging-cost.md)
* [35-service-fee](35-service-fee.md)

---

## برگشت و اصلاح

* [36-sale-return-full](36-sale-return-full.md)
* [37-sale-return-partial](37-sale-return-partial.md)
* [38-sale-refund](38-sale-refund.md)
* [39-sale-credit-on-return](39-sale-credit-on-return.md)
* [40-sale-price-adjustment](40-sale-price-adjustment.md)
* [41-sale-quantity-adjustment](41-sale-quantity-adjustment.md)

---

## موجودی و بهای تمام‌شده

* [42-sale-fifo](42-sale-fifo.md)
* [43-sale-average-cost](43-sale-average-cost.md)
* [44-sale-zero-cost](44-sale-zero-cost.md)
* [45-sale-service-only](45-sale-service-only.md)
* [46-sale-product-and-service](46-sale-product-and-service.md)

---

## سناریوهای خاص

* [47-installment-sale](47-installment-sale.md)
* [48-sale-with-deposit](48-sale-with-deposit.md)
* [49-commission-sale](49-commission-sale.md)
* [50-dropshipping-sale](50-dropshipping-sale.md)
* [51-marketplace-sale](51-marketplace-sale.md)
* [52-multi-currency-sale](52-multi-currency-sale.md)

---

## اسناد وابسته

* [53-proforma-invoice](53-proforma-invoice.md)
* [54-proforma-to-invoice](54-proforma-to-invoice.md)
* [55-invoice-cancel](55-invoice-cancel.md)
* [56-invoice-adjustment](56-invoice-adjustment.md)
* [57-bulk-invoice](57-bulk-invoice.md)
* [58-subscription-invoice](58-subscription-invoice.md)

---

## لیست حساب‌های مورد نیاز

### دارایی‌ها

* 110101 صندوق
* 110102 بانک
* 110201 حساب‌های دریافتنی
* 120101 موجودی کالا
* 110301 پیش‌دریافت از مشتری

### بدهی‌ها

* 210301 مالیات پرداختنی
* 210401 ودیعه / سپرده مشتری

### درآمدها

* 410101 درآمد فروش کالا
* 410102 درآمد فروش خدمات
* 410201 درآمد باربری
* 410301 درآمد جرایم و دیرکرد

### هزینه‌ها

* 510101 بهای تمام‌شده کالای فروش‌رفته
* 520201 هزینه حمل پرداختی

---

## قوانین طلایی ثبت فروش

* تخفیف حساب مستقل ندارد و باعث کاهش درآمد می‌شود
* مالیات هرگز درآمد نیست
* باربری می‌تواند درآمد یا هزینه باشد
* سند فروش حداقل شامل چهار حساب است
* فاکتور و سند حسابداری از نظر عددی منطبق‌اند اما ساختارشان متفاوت است
* اعداد و درصدهای رند قابل پیگیری چشمی هستند؛ عدد نهایی را گرد نکنید — در سند نهایی جمع بدهکار و بستانکار باید صفر باشد

---

## ترتیب پیشنهادی مطالعه

1. فروش نقدی
2. فروش نسیه
3. تخفیف‌ها
4. مالیات
5. برگشت و اصلاح

این فایل مبنای توسعه تمام فروشگاه‌ها با هر اندازه و منطق تجاری است.
