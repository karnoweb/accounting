# مثال‌های فروشگاهی

این پوشه **مرجع رفتاری هسته پکیج نیست**.  
سناریوهای اینجا مثال‌های مفهومی برای یک دامنه فروشگاهی هستند و معمولاً به مفاهیمی وابسته‌اند که در خود پکیج به‌صورت مستقل پیاده‌سازی نشده‌اند؛ مثل:

- کالا و موجودی
- مالیات
- باربری
- مشتری و فروشنده
- کیف پول
- سیاست تخفیف

بنابراین هر فایل این پوشه باید با این پیش‌فرض خوانده شود:

1. **الگوی ثبت حسابداری** می‌تواند مفید باشد
2. اما **منبع حقیقت هسته پکیج نیست**
3. اجرای واقعی آن نیازمند منطق اپلیکیشن مصرف‌کننده است

## این مثال‌ها برای چه کاری مناسب‌اند؟

- آموزش ذهنی برای تحلیل دوبل ثبت‌ها
- کمک به طراحی serviceهای اپلیکیشن مصرف‌کننده
- توافق تیم روی تفسیر حسابداری سناریوهای فروشگاهی

## این مثال‌ها برای چه کاری مناسب نیستند؟

- اثبات قابلیت هسته پکیج
- استنتاج APIهای جدید از روی متن مثال
- نتیجه‌گیری درباره وجود ماژول‌هایی مثل مالیات، انبار یا بانک در خود پکیج

## فهرست واقعی فایل‌های موجود

### فروش نقدی

- [01-sale-cash-no-discount](01-sale-cash-no-discount.md)
- [02-sale-cash-product-discount](02-sale-cash-product-discount.md)
- [03-sale-cash-invoice-discount](03-sale-cash-invoice-discount.md)
- [04-sale-cash-product-and-invoice-discount](04-sale-cash-product-and-invoice-discount.md)
- [05-sale-cash-no-tax](05-sale-cash-no-tax.md)
- [06-sale-cash-with-tax](06-sale-cash-with-tax.md)
- [07-sale-cash-with-shipping-taxable](07-sale-cash-with-shipping-taxable.md)
- [08-sale-cash-with-shipping-non-taxable](08-sale-cash-with-shipping-non-taxable.md)

### فروش نسیه و تسویه

- [09-sale-credit](09-sale-credit.md)
- [10-sale-credit-with-advance](10-sale-credit-with-advance.md)
- [11-sale-credit-multi-due](11-sale-credit-multi-due.md)
- [12-sale-credit-with-penalty](12-sale-credit-with-penalty.md)
- [13-credit-settlement-full](13-credit-settlement-full.md)
- [14-credit-settlement-partial](14-credit-settlement-partial.md)
- [15-sale-mixed-cash-credit](15-sale-mixed-cash-credit.md)
- [16-sale-wallet](16-sale-wallet.md)
- [17-sale-credit-note](17-sale-credit-note.md)

### تخفیف

- [18-product-discount](18-product-discount.md)
- [19-invoice-discount](19-invoice-discount.md)
- [20-post-issue-discount](20-post-issue-discount.md)
- [21-campaign-discount](21-campaign-discount.md)
- [22-volume-discount](22-volume-discount.md)
- [23-customer-group-discount](23-customer-group-discount.md)

### مالیات

- [24-sale-taxable](24-sale-taxable.md)
- [25-sale-non-taxable](25-sale-non-taxable.md)
- [26-mixed-tax-items](26-mixed-tax-items.md)
- [27-multi-tax-rate](27-multi-tax-rate.md)
- [28-tax-adjustment](28-tax-adjustment.md)
- [29-tax-return](29-tax-return.md)

### باربری و هزینه‌های جانبی

- [30-shipping-income](30-shipping-income.md)
- [31-shipping-expense](31-shipping-expense.md)
- [32-shipping-fixed](32-shipping-fixed.md)
- [33-shipping-variable](33-shipping-variable.md)
- [34-packaging-cost](34-packaging-cost.md)
- [35-service-fee](35-service-fee.md)

## راهنمای خواندن هر سناریو

وقتی یکی از این فایل‌ها را می‌خوانید، این سؤال‌ها را جداگانه پاسخ دهید:

1. کدام بخش صرفاً تحلیل حسابداری سناریو است؟
2. کدام حساب‌ها واقعاً در chart of accounts پروژه شما وجود دارند؟
3. کدام بخش به منطق اپلیکیشن شما وابسته است؟
4. آیا این سناریو با contract فعلی هسته پکیج قابل پیاده‌سازی است، یا نیاز به لایه بالاتر دارد؟

## نکته مهم

اگر بین این مثال‌ها و مستندات canonical داخل `docs/` اختلاف وجود داشته باشد، مستندات canonical و در نهایت **کد پکیج** مبنا هستند.
