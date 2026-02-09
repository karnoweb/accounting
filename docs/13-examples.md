# 13-examples.md

# مثال‌های کاربردی

## Examples

---

## مقدمه

این بخش شامل مثال‌های کاربردی برای سناریوهای مختلف کسب‌وکار است. هر مثال شامل توضیح سناریو، کد پیاده‌سازی و نتیجه مورد انتظار است.

---

## ۱. فروشگاه آنلاین

### ۱.۱ ساختار حساب‌ها

| کد | عنوان | نوع | کاربرد |
|----|-------|-----|--------|
| 110101 | صندوق | دارایی | دریافت نقدی |
| 110201 | بانک اصلی | دارایی | پرداخت‌های بانکی |
| 110301 | بدهکاران | دارایی | بدهی مشتریان |
| 110401 | موجودی کالا | دارایی | موجودی انبار |
| 210101 | بستانکاران | بدهی | بدهی به تأمین‌کنندگان |
| 410101 | درآمد فروش | درآمد | فروش کالا |
| 510101 | بهای تمام شده | هزینه | بهای کالای فروش رفته |

### ۱.۲ سرویس حسابداری فروشگاه

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Models\Supplier;
use YourVendor\Accounting\Facades\Accounting;
use YourVendor\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;

class ShopAccountingService
{
    /**
     * فروش نقدی
     */
    public function recordCashSale(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $doc = Accounting::document()
                ->type('sale')
                ->date($order->completed_at ?? now())
                ->reference($order->order_number)
                ->description("فروش نقدی - سفارش {$order->order_number}");
            
            // دریافت وجه
            $doc->debit(
                $this->getCashAccount(),
                $order->total_amount,
                'دریافت نقدی'
            );
            
            $doc->credit(
                $this->getSalesAccount(),
                $order->total_amount,
                'درآمد فروش'
            );
            
            // بهای تمام شده هر آیتم
            foreach ($order->items as $item) {
                $cost = $item->quantity * $item->product->cost_price;
                
                $doc->debit(
                    $this->getCostOfGoodsAccount(),
                    $cost,
                    "بهای تمام شده - {$item->product->name}"
                );
                
                $doc->credit(
                    $item->product->account,
                    $cost,
                    "خروج از انبار - {$item->product->name}"
                );
            }
            
            $doc->post();
        });
    }
    
    /**
     * فروش نسیه (اعتباری)
     */
    public function recordCreditSale(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $customer = $order->customer;
            
            $doc = Accounting::document()
                ->type('sale')
                ->date($order->completed_at ?? now())
                ->reference($order->order_number)
                ->description("فروش نسیه به {$customer->name}");
            
            // بدهکار شدن مشتری
            $doc->debit(
                $customer->account,
                $order->total_amount,
                'بدهکار شدن مشتری'
            );
            
            $doc->credit(
                $this->getSalesAccount(),
                $order->total_amount,
                'درآمد فروش'
            );
            
            // بهای تمام شده
            foreach ($order->items as $item) {
                $cost = $item->quantity * $item->product->cost_price;
                
                $doc->debit($this->getCostOfGoodsAccount(), $cost);
                $doc->credit($item->product->account, $cost);
            }
            
            $doc->post();
        });
    }
    
    /**
     * دریافت از مشتری
     */
    public function recordPaymentFromCustomer(
        User $customer,
        float $amount,
        string $method = 'cash',
        ?string $reference = null
    ): void {
        $paymentAccount = $method === 'cash'
            ? $this->getCashAccount()
            : $this->getBankAccount();
        
        Accounting::document()
            ->type('receipt')
            ->date(now())
            ->reference($reference)
            ->description("دریافت از {$customer->name}")
            ->debit($paymentAccount, $amount, "دریافت {$method}")
            ->credit($customer->account, $amount, 'تسویه بدهی')
            ->post();
    }
    
    /**
     * خرید از تأمین‌کننده
     */
    public function recordPurchase(
        Supplier $supplier,
        array $items,
        ?string $invoiceNumber = null
    ): void {
        DB::transaction(function () use ($supplier, $items, $invoiceNumber) {
            $doc = Accounting::document()
                ->type('purchase')
                ->date(now())
                ->reference($invoiceNumber)
                ->description("خرید از {$supplier->name}");
            
            $totalAmount = 0;
            
            foreach ($items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $totalAmount += $amount;
                
                $product = Product::find($item['product_id']);
                
                $doc->debit(
                    $product->account,
                    $amount,
                    "ورود به انبار - {$product->name}"
                );
            }
            
            $doc->credit(
                $supplier->account,
                $totalAmount,
                'بدهی به تأمین‌کننده'
            );
            
            $doc->post();
        });
    }
    
    /**
     * پرداخت به تأمین‌کننده
     */
    public function recordPaymentToSupplier(
        Supplier $supplier,
        float $amount,
        string $method = 'bank',
        ?string $reference = null
    ): void {
        $paymentAccount = $method === 'cash'
            ? $this->getCashAccount()
            : $this->getBankAccount();
        
        Accounting::document()
            ->type('payment')
            ->date(now())
            ->reference($reference)
            ->description("پرداخت به {$supplier->name}")
            ->debit($supplier->account, $amount, 'تسویه بدهی')
            ->credit($paymentAccount, $amount, "پرداخت {$method}")
            ->post();
    }
    
    /**
     * برگشت از فروش
     */
    public function recordSalesReturn(Order $order, array $returnedItems): void
    {
        DB::transaction(function () use ($order, $returnedItems) {
            $customer = $order->customer;
            $totalReturn = 0;
            
            $doc = Accounting::document()
                ->type('sales_return')
                ->date(now())
                ->reference($order->order_number)
                ->description("برگشت از فروش - سفارش {$order->order_number}");
            
            foreach ($returnedItems as $item) {
                $product = Product::find($item['product_id']);
                $amount = $item['quantity'] * $item['unit_price'];
                $cost = $item['quantity'] * $product->cost_price;
                $totalReturn += $amount;
                
                // برگشت به انبار
                $doc->debit($product->account, $cost);
                $doc->credit($this->getCostOfGoodsAccount(), $cost);
            }
            
            // کاهش درآمد و بدهی مشتری
            $doc->debit($this->getSalesAccount(), $totalReturn, 'برگشت از فروش');
            $doc->credit($customer->account, $totalReturn, 'کاهش بدهی');
            
            $doc->post();
        });
    }
    
    // Helper methods
    private function getCashAccount(): Account
    {
        return Accounting::systemAccount('cash');
    }
    
    private function getBankAccount(): Account
    {
        return Accounting::systemAccount('bank');
    }
    
    private function getSalesAccount(): Account
    {
        return Accounting::systemAccount('sales_income');
    }
    
    private function getCostOfGoodsAccount(): Account
    {
        return Accounting::systemAccount('cost_of_goods');
    }
}
```

### ۱.۳ استفاده در Controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ShopAccountingService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private ShopAccountingService $accountingService
    ) {}
    
    public function complete(Order $order)
    {
        // تغییر وضعیت سفارش
        $order->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        
        // ثبت در حسابداری
        if ($order->payment_method === 'cash' || $order->is_paid) {
            $this->accountingService->recordCashSale($order);
        } else {
            $this->accountingService->recordCreditSale($order);
        }
        
        return redirect()->route('orders.show', $order)
            ->with('success', 'سفارش تکمیل و در حسابداری ثبت شد.');
    }
}
```

---

## ۲. آموزشگاه

### ۲.۱ ساختار حساب‌ها

| کد | عنوان | نوع | کاربرد |
|----|-------|-----|--------|
| 110101 | صندوق | دارایی | شهریه نقدی |
| 110201 | بانک | دارایی | شهریه بانکی |
| 110301 | بدهکاران - دانشجویان | دارایی | شهریه معوق |
| 210101 | پیش‌دریافت شهریه | بدهی | شهریه پرداخت شده قبل از ترم |
| 410101 | درآمد شهریه | درآمد | درآمد ثبت‌نام |
| 410102 | درآمد دوره‌های آزاد | درآمد | کلاس‌های فوق‌العاده |
| 510101 | هزینه حقوق مدرسین | هزینه | پرداخت به مدرسین |
| 510102 | هزینه اجاره | هزینه | اجاره ساختمان |

### ۲.۲ سرویس حسابداری آموزشگاه

```php
<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Enrollment;
use App\Models\Course;
use YourVendor\Accounting\Facades\Accounting;
use Illuminate\Support\Facades\DB;

class AcademyAccountingService
{
    /**
     * ثبت‌نام دانشجو با پرداخت کامل
     */
    public function recordFullPaymentEnrollment(Enrollment $enrollment): void
    {
        $student = $enrollment->student;
        $course = $enrollment->course;
        
        Accounting::document()
            ->type('tuition')
            ->date(now())
            ->reference($enrollment->enrollment_number)
            ->description("ثبت‌نام {$student->name} در {$course->name}")
            ->debit($this->getCashAccount(), $enrollment->amount, 'دریافت شهریه')
            ->credit($this->getTuitionIncomeAccount(), $enrollment->amount, 'درآمد شهریه')
            ->post();
    }
    
    /**
     * ثبت‌نام دانشجو با پرداخت اقساطی
     */
    public function recordInstallmentEnrollment(
        Enrollment $enrollment,
        float $downPayment
    ): void {
        $student = $enrollment->student;
        $course = $enrollment->course;
        $remainingAmount = $enrollment->amount - $downPayment;
        
        $doc = Accounting::document()
            ->type('tuition')
            ->date(now())
            ->reference($enrollment->enrollment_number)
            ->description("ثبت‌نام اقساطی {$student->name} در {$course->name}");
        
        // پیش‌پرداخت
        if ($downPayment > 0) {
            $doc->debit($this->getCashAccount(), $downPayment, 'پیش‌پرداخت');
        }
        
        // بدهی دانشجو
        if ($remainingAmount > 0) {
            $doc->debit($student->account, $remainingAmount, 'بدهی شهریه');
        }
        
        // درآمد کل
        $doc->credit($this->getTuitionIncomeAccount(), $enrollment->amount, 'درآمد شهریه');
        
        $doc->post();
    }
    
    /**
     * دریافت قسط از دانشجو
     */
    public function recordInstallmentPayment(
        Student $student,
        float $amount,
        string $method = 'cash',
        ?string $reference = null
    ): void {
        $paymentAccount = $method === 'cash'
            ? $this->getCashAccount()
            : $this->getBankAccount();
        
        Accounting::document()
            ->type('receipt')
            ->date(now())
            ->reference($reference)
            ->description("دریافت قسط از {$student->name}")
            ->debit($paymentAccount, $amount, "دریافت {$method}")
            ->credit($student->account, $amount, 'تسویه قسط')
            ->post();
    }
    
    /**
     * پیش‌دریافت شهریه (قبل از شروع ترم)
     */
    public function recordAdvancePayment(
        Student $student,
        Course $course,
        float $amount
    ): void {
        Accounting::document()
            ->type('advance_receipt')
            ->date(now())
            ->description("پیش‌دریافت شهریه {$course->name} از {$student->name}")
            ->debit($this->getCashAccount(), $amount, 'دریافت نقدی')
            ->credit($this->getAdvancePaymentAccount(), $amount, 'پیش‌دریافت شهریه')
            ->post();
    }
    
    /**
     * تبدیل پیش‌دریافت به درآمد (شروع ترم)
     */
    public function recognizeAdvanceAsIncome(
        Student $student,
        Course $course,
        float $amount
    ): void {
        Accounting::document()
            ->type('income_recognition')
            ->date(now())
            ->description("شناسایی درآمد {$course->name} - {$student->name}")
            ->debit($this->getAdvancePaymentAccount(), $amount, 'کاهش پیش‌دریافت')
            ->credit($this->getTuitionIncomeAccount(), $amount, 'درآمد شهریه')
            ->post();
    }
    
    /**
     * پرداخت حقوق مدرس
     */
    public function recordTeacherSalary(
        Teacher $teacher,
        float $amount,
        string $period,
        ?string $costCenterId = null
    ): void {
        $doc = Accounting::document()
            ->type('salary')
            ->date(now())
            ->description("پرداخت حقوق {$teacher->name} - {$period}");
        
        $debitItem = $doc->debit(
            $this->getTeacherSalaryAccount(),
            $amount,
            "حقوق {$period}"
        );
        
        if ($costCenterId) {
            $debitItem->costCenter($costCenterId);
        }
        
        $doc->credit($this->getBankAccount(), $amount, 'پرداخت بانکی')
            ->post();
    }
    
    /**
     * ثبت هزینه کلاس
     */
    public function recordClassExpense(
        string $description,
        float $amount,
        string $expenseType,
        ?Course $course = null
    ): void {
        $expenseAccount = $this->getExpenseAccount($expenseType);
        
        $doc = Accounting::document()
            ->type('expense')
            ->date(now())
            ->description($description);
        
        $debitItem = $doc->debit($expenseAccount, $amount, $description);
        
        if ($course) {
            $debitItem->costCenter($course->cost_center_id);
        }
        
        $doc->credit($this->getCashAccount(), $amount, 'پرداخت نقدی')
            ->post();
    }
    
    /**
     * گزارش وضعیت مالی دانشجو
     */
    public function getStudentFinancialStatus(Student $student): array
    {
        $balance = $student->balance();
        $enrollments = $student->enrollments()->with('course')->get();
        $payments = $student->documents()->where('type', 'receipt')->get();
        
        return [
            'student' => $student,
            'balance' => $balance,
            'balance_status' => $balance > 0 ? 'بدهکار' : ($balance < 0 ? 'بستانکار' : 'تسویه'),
            'total_tuition' => $enrollments->sum('amount'),
            'total_paid' => $payments->sum(fn($doc) => $doc->items->where('sign', 1)->sum('amount')),
            'enrollments' => $enrollments,
            'payments' => $payments,
        ];
    }
    
    // Helper methods
    private function getCashAccount() { return Accounting::systemAccount('cash'); }
    private function getBankAccount() { return Accounting::systemAccount('bank'); }
    private function getTuitionIncomeAccount() { return Accounting::account()->findByCode('410101'); }
    private function getAdvancePaymentAccount() { return Accounting::account()->findByCode('210101'); }
    private function getTeacherSalaryAccount() { return Accounting::account()->findByCode('510101'); }
    
    private function getExpenseAccount(string $type)
    {
        return match($type) {
            'salary' => Accounting::account()->findByCode('510101'),
            'rent' => Accounting::account()->findByCode('510102'),
            'utilities' => Accounting::account()->findByCode('510103'),
            default => Accounting::account()->findByCode('510199'),
        };
    }
}
```

---

## ۳. شرکت خدماتی

### ۳.۱ ساختار حساب‌ها

| کد | عنوان | نوع | کاربرد |
|----|-------|-----|--------|
| 110101 | صندوق | دارایی | دریافت نقدی |
| 110201 | بانک | دارایی | تراکنش‌های بانکی |
| 110301 | بدهکاران | دارایی | صورتحساب‌های معوق |
| 210101 | بستانکاران | بدهی | بدهی به تأمین‌کنندگان |
| 410101 | درآمد خدمات | درآمد | خدمات ارائه شده |
| 410102 | درآمد مشاوره | درآمد | مشاوره |
| 510101 | هزینه حقوق | هزینه | حقوق کارمندان |
| 510102 | هزینه اجاره | هزینه | اجاره دفتر |
| 510103 | هزینه آب و برق | هزینه | قبوض |

### ۳.۲ سرویس حسابداری خدماتی

```php
<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Employee;
use YourVendor\Accounting\Facades\Accounting;
use Illuminate\Support\Facades\DB;

class ServiceAccountingService
{
    /**
     * صدور صورتحساب
     */
    public function issueInvoice(Invoice $invoice): void
    {
        $client = $invoice->client;
        
        Accounting::document()
            ->type('invoice')
            ->date($invoice->issue_date)
            ->reference($invoice->invoice_number)
            ->description("صورتحساب {$invoice->invoice_number} - {$client->name}")
            ->debit($client->account, $invoice->total_amount, 'بدهکار شدن مشتری')
            ->credit($this->getServiceIncomeAccount(), $invoice->total_amount, 'درآمد خدمات')
            ->post();
    }
    
    /**
     * صدور صورتحساب با مرکز هزینه (پروژه‌ای)
     */
    public function issueProjectInvoice(Invoice $invoice, Project $project): void
    {
        $client = $invoice->client;
        
        $doc = Accounting::document()
            ->type('invoice')
            ->date($invoice->issue_date)
            ->reference($invoice->invoice_number)
            ->description("صورتحساب پروژه {$project->name}");
        
        $doc->debit($client->account, $invoice->total_amount);
        
        $doc->credit($this->getServiceIncomeAccount(), $invoice->total_amount)
            ->costCenter($project->cost_center_id);
        
        $doc->post();
    }
    
    /**
     * دریافت وجه صورتحساب
     */
    public function recordInvoicePayment(
        Invoice $invoice,
        float $amount,
        string $method = 'bank'
    ): void {
        $client = $invoice->client;
        $paymentAccount = $method === 'cash'
            ? $this->getCashAccount()
            : $this->getBankAccount();
        
        Accounting::document()
            ->type('receipt')
            ->date(now())
            ->reference($invoice->invoice_number)
            ->description("دریافت بابت صورتحساب {$invoice->invoice_number}")
            ->debit($paymentAccount, $amount, "دریافت {$method}")
            ->credit($client->account, $amount, 'تسویه صورتحساب')
            ->post();
        
        // بروزرسانی صورتحساب
        $invoice->paid_amount += $amount;
        if ($invoice->paid_amount >= $invoice->total_amount) {
            $invoice->status = 'paid';
        } else {
            $invoice->status = 'partial';
        }
        $invoice->save();
    }
    
    /**
     * پرداخت حقوق با تفکیک پروژه
     */
    public function recordProjectBasedSalary(
        Employee $employee,
        array $projectAllocations,
        string $period
    ): void {
        $doc = Accounting::document()
            ->type('salary')
            ->date(now())
            ->description("پرداخت حقوق {$employee->name} - {$period}");
        
        $totalAmount = 0;
        
        foreach ($projectAllocations as $allocation) {
            $project = Project::find($allocation['project_id']);
            $amount = $allocation['amount'];
            $totalAmount += $amount;
            
            $doc->debit($this->getSalaryExpenseAccount(), $amount, "حقوق - {$project->name}")
                ->costCenter($project->cost_center_id);
        }
        
        $doc->credit($this->getBankAccount(), $totalAmount, 'پرداخت حقوق')
            ->post();
    }
    
    /**
     * ثبت هزینه پروژه
     */
    public function recordProjectExpense(
        Project $project,
        string $expenseType,
        float $amount,
        string $description
    ): void {
        $expenseAccount = $this->getExpenseAccount($expenseType);
        
        Accounting::document()
            ->type('expense')
            ->date(now())
            ->description("{$description} - پروژه {$project->name}")
            ->debit($expenseAccount, $amount, $description)
                ->costCenter($project->cost_center_id)
            ->credit($this->getCashAccount(), $amount, 'پرداخت نقدی')
            ->post();
    }
    
    /**
     * گزارش سودآوری پروژه
     */
    public function getProjectProfitability(Project $project): array
    {
        $report = Accounting::report()->costCenterReport(
            costCenter: $project->cost_center_id
        );
        
        return [
            'project' => $project,
            'income' => $report['income']['total'],
            'expenses' => $report['expenses']['total'],
            'profit' => $report['profit'],
            'margin' => $report['margin'],
            'expense_breakdown' => $report['expenses']['items'],
        ];
    }
    
    /**
     * گزارش درآمد ماهانه
     */
    public function getMonthlyRevenueReport(int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        
        $income = Accounting::report()->incomeStatement(
            fromDate: $startDate,
            toDate: $endDate
        );
        
        $invoices = Invoice::whereBetween('issue_date', [$startDate, $endDate])->get();
        
        return [
            'period' => "{$year}/{$month}",
            'total_income' => $income['income']['total'],
            'invoices_issued' => $invoices->count(),
            'invoices_amount' => $invoices->sum('total_amount'),
            'collected' => $invoices->sum('paid_amount'),
            'outstanding' => $invoices->sum('total_amount') - $invoices->sum('paid_amount'),
        ];
    }
    
    // Helper methods
    private function getCashAccount() { return Accounting::systemAccount('cash'); }
    private function getBankAccount() { return Accounting::systemAccount('bank'); }
    private function getServiceIncomeAccount() { return Accounting::account()->findByCode('410101'); }
    private function getSalaryExpenseAccount() { return Accounting::account()->findByCode('510101'); }
    
    private function getExpenseAccount(string $type)
    {
        return match($type) {
            'salary' => Accounting::account()->findByCode('510101'),
            'rent' => Accounting::account()->findByCode('510102'),
            'utilities' => Accounting::account()->findByCode('510103'),
            'travel' => Accounting::account()->findByCode('510104'),
            'supplies' => Accounting::account()->findByCode('510105'),
            default => Accounting::account()->findByCode('510199'),
        };
    }
}
```

---

## ۴. عملیات عمومی

### ۴.۱ انتقال بین حساب‌ها

```php
<?php

namespace App\Services;

use YourVendor\Accounting\Facades\Accounting;
use YourVendor\Accounting\Models\Account;

class TransferService
{
    /**
     * انتقال وجه از صندوق به بانک
     */
    public function cashToBank(float $amount, string $description = ''): void
    {
        Accounting::document()
            ->type('transfer')
            ->date(now())
            ->description($description ?: 'واریز وجه نقد به بانک')
            ->debit(Accounting::systemAccount('bank'), $amount, 'واریز به بانک')
            ->credit(Accounting::systemAccount('cash'), $amount, 'برداشت از صندوق')
            ->post();
    }
    
    /**
     * انتقال از بانک به صندوق
     */
    public function bankToCash(float $amount, string $description = ''): void
    {
        Accounting::document()
            ->type('transfer')
            ->date(now())
            ->description($description ?: 'برداشت نقدی از بانک')
            ->debit(Accounting::systemAccount('cash'), $amount, 'دریافت نقدی')
            ->credit(Accounting::systemAccount('bank'), $amount, 'برداشت از بانک')
            ->post();
    }
    
    /**
     * انتقال بین دو بانک
     */
    public function bankToBank(
        Account $fromBank,
        Account $toBank,
        float $amount,
        ?string $reference = null
    ): void {
        Accounting::document()
            ->type('transfer')
            ->date(now())
            ->reference($reference)
            ->description("انتقال از {$fromBank->title} به {$toBank->title}")
            ->debit($toBank, $amount, "واریز از {$fromBank->title}")
            ->credit($fromBank, $amount, "انتقال به {$toBank->title}")
            ->post();
    }
    
    /**
     * تنخواه‌گردان
     */
    public function issuePettyCash(float $amount, string $recipient): void
    {
        $pettyCashAccount = Accounting::account()->findByCode('110102');
        
        Accounting::document()
            ->type('petty_cash')
            ->date(now())
            ->description("صدور تنخواه برای {$recipient}")
            ->debit($pettyCashAccount, $amount, "تنخواه {$recipient}")
            ->credit(Accounting::systemAccount('cash'), $amount, 'خروج از صندوق')
            ->post();
    }
    
    /**
     * تسویه تنخواه‌گردان
     */
    public function settlePettyCash(array $expenses, float $returnedCash = 0): void
    {
        $pettyCashAccount = Accounting::account()->findByCode('110102');
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        
        $doc = Accounting::document()
            ->type('petty_cash_settlement')
            ->date(now())
            ->description('تسویه تنخواه‌گردان');
        
        // ثبت هزینه‌ها
        foreach ($expenses as $expense) {
            $expenseAccount = Accounting::account()->findByCode($expense['account_code']);
            $doc->debit($expenseAccount, $expense['amount'], $expense['description']);
        }
        
        // برگشت وجه نقد (اگر موجود)
        if ($returnedCash > 0) {
            $doc->debit(Accounting::systemAccount('cash'), $returnedCash, 'برگشت تنخواه');
        }
        
        // بستن تنخواه
        $doc->credit($pettyCashAccount, $totalExpenses + $returnedCash, 'تسویه تنخواه');
        
        $doc->post();
    }
}
```

### ۴.۲ ثبت هزینه‌ها

```php
<?php

namespace App\Services;

use YourVendor\Accounting\Facades\Accounting;
use YourVendor\Accounting\Models\CostCenter;

class ExpenseService
{
    /**
     * ثبت هزینه عمومی
     */
    public function recordExpense(
        string $expenseCode,
        float $amount,
        string $description,
        string $paymentMethod = 'cash',
        ?CostCenter $costCenter = null
    ): void {
        $expenseAccount = Accounting::account()->findByCode($expenseCode);
        $paymentAccount = $paymentMethod === 'cash'
            ? Accounting::systemAccount('cash')
            : Accounting::systemAccount('bank');
        
        $doc = Accounting::document()
            ->type('expense')
            ->date(now())
            ->description($description);
        
        $debitItem = $doc->debit($expenseAccount, $amount, $description);
        
        if ($costCenter) {
            $debitItem->costCenter($costCenter);
        }
        
        $doc->credit($paymentAccount, $amount, "پرداخت {$paymentMethod}")
            ->post();
    }
    
    /**
     * ثبت قبض
     */
    public function recordUtilityBill(
        string $type,
        float $amount,
        string $billNumber,
        string $period
    ): void {
        $expenseCode = match($type) {
            'electricity' => '510301',
            'water' => '510302',
            'gas' => '510303',
            'phone' => '510304',
            'internet' => '510305',
            default => '510399',
        };
        
        $this->recordExpense(
            expenseCode: $expenseCode,
            amount: $amount,
            description: "قبض {$type} - {$period} - {$billNumber}",
            paymentMethod: 'bank'
        );
    }
    
    /**
     * ثبت اجاره
     */
    public function recordRent(
        float $amount,
        string $period,
        ?string $contractNumber = null
    ): void {
        Accounting::document()
            ->type('rent')
            ->date(now())
            ->reference($contractNumber)
            ->description("اجاره {$period}")
            ->debit(Accounting::account()->findByCode('510102'), $amount, "اجاره {$period}")
            ->credit(Accounting::systemAccount('bank'), $amount, 'پرداخت اجاره')
            ->post();
    }
}
```

---

## ۵. سال مالی

### ۵.۱ مدیریت سال مالی

```php
<?php

namespace App\Services;

use YourVendor\Accounting\Facades\Accounting;
use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Models\Account;
use Carbon\Carbon;

class FiscalYearManagementService
{
    /**
     * ایجاد سال مالی جدید
     */
    public function createNewFiscalYear(string $title, Carbon $startDate, Carbon $endDate): FiscalYear
    {
        return Accounting::fiscalYear()->create([
            'title' => $title,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'draft',
        ]);
    }
    
    /**
     * افتتاح سال مالی جدید از روی سال قبل
     */
    public function openNewFiscalYear(FiscalYear $newYear, FiscalYear $previousYear): void
    {
        // دریافت مانده حساب‌های دائمی
        $permanentAccounts = Account::whereIn('type', ['asset', 'liability', 'equity'])
            ->where('level', 3)
            ->get();
        
        $doc = Accounting::document()
            ->type('opening')
            ->date($newYear->start_date)
            ->fiscalYear($newYear)
            ->description("سند افتتاحیه سال مالی {$newYear->title}");
        
        foreach ($permanentAccounts as $account) {
            $balance = $account->balanceInFiscalYear($previousYear);
            
            if (abs($balance) < 0.01) {
                continue;
            }
            
            if ($balance > 0) {
                $doc->debit($account, $balance, 'مانده از سال قبل');
            } else {
                $doc->credit($account, abs($balance), 'مانده از سال قبل');
            }
        }
        
        $doc->post();
        
        // فعال کردن سال جدید
        $newYear->update([
            'status' => 'active',
            'is_current' => true,
            'opening_done' => true,
            'opened_at' => now(),
        ]);
        
        // غیرفعال کردن سال قبل
        $previousYear->update(['is_current' => false]);
    }
    
    /**
     * بستن سال مالی
     */
    public function closeFiscalYear(FiscalYear $fiscalYear): void
    {
        // بررسی اسناد پیش‌نویس
        $draftCount = $fiscalYear->documents()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            throw new \Exception("تعداد {$draftCount} سند پیش‌نویس وجود دارد.");
        }
        
        // محاسبه سود/زیان
        $income = Account::where('type', 'income')
            ->get()
            ->sum(fn($a) => $a->balanceInFiscalYear($fiscalYear));
        
        $expenses = Account::where('type', 'expense')
            ->get()
            ->sum(fn($a) => $a->balanceInFiscalYear($fiscalYear));
        
        $netProfit = abs($income) - abs($expenses);
        
        // ثبت سند اختتامیه
        $this->createClosingEntry($fiscalYear, $netProfit);
        
        // بستن سال مالی
        $fiscalYear->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
    
    /**
     * سند اختتامیه
     */
    private function createClosingEntry(FiscalYear $fiscalYear, float $netProfit): void
    {
        $doc = Accounting::document()
            ->type('closing')
            ->date($fiscalYear->end_date)
            ->fiscalYear($fiscalYear)
            ->description("سند اختتامیه سال مالی {$fiscalYear->title}");
        
        // بستن حساب‌های درآمد
        $incomeAccounts = Account::where('type', 'income')->where('level', 3)->get();
        foreach ($incomeAccounts as $account) {
            $balance = $account->balanceInFiscalYear($fiscalYear);
            if (abs($balance) > 0.01) {
                $doc->debit($account, abs($balance), 'بستن حساب درآمد');
            }
        }
        
        // بستن حساب‌های هزینه
        $expenseAccounts = Account::where('type', 'expense')->where('level', 3)->get();
        foreach ($expenseAccounts as $account) {
            $balance = $account->balanceInFiscalYear($fiscalYear);
            if (abs($balance) > 0.01) {
                $doc->credit($account, abs($balance), 'بستن حساب هزینه');
            }
        }
        
        // انتقال به سود انباشته
        $retainedEarnings = Accounting::systemAccount('retained_earnings');
        if ($netProfit >= 0) {
            $doc->credit($retainedEarnings, $netProfit, 'سود خالص دوره');
        } else {
            $doc->debit($retainedEarnings, abs($netProfit), 'زیان خالص دوره');
        }
        
        $doc->post();
    }
}
```

---

## ۶. موجودی اولیه

### ۶.۱ ثبت موجودی اولیه

```php
<?php

namespace App\Services;

use YourVendor\Accounting\Facades\Accounting;
use YourVendor\Accounting\Models\FiscalYear;

class OpeningBalanceService
{
    /**
     * ثبت موجودی اولیه کامل
     */
    public function recordOpeningBalances(FiscalYear $fiscalYear, array $balances): void
    {
        $doc = Accounting::document()
            ->type('opening')
            ->date($fiscalYear->start_date)
            ->fiscalYear($fiscalYear)
            ->description("موجودی اولیه سال مالی {$fiscalYear->title}");
        
        foreach ($balances as $balance) {
            $account = Accounting::account()->findByCode($balance['account_code']);
            $amount = $balance['amount'];
            
            if ($amount > 0) {
                // دارایی و هزینه: بدهکار
                if (in_array($account->type, ['asset', 'expense'])) {
                    $doc->debit($account, $amount, 'موجودی اولیه');
                }
                // بدهی، سرمایه و درآمد: بستانکار
                else {
                    $doc->credit($account, $amount, 'موجودی اولیه');
                }
            } else {
                // مانده معکوس
                $amount = abs($amount);
                if (in_array($account->type, ['asset', 'expense'])) {
                    $doc->credit($account, $amount, 'موجودی اولیه');
                } else {
                    $doc->debit($account, $amount, 'موجودی اولیه');
                }
            }
        }
        
        $doc->post();
        
        $fiscalYear->update(['opening_done' => true]);
    }
    
    /**
     * مثال داده موجودی اولیه
     */
    public function getSampleOpeningData(): array
    {
        return [
            ['account_code' => '110101', 'amount' => 5000000],   // صندوق
            ['account_code' => '110201', 'amount' => 50000000],  // بانک
            ['account_code' => '110401', 'amount' => 30000000],  // موجودی کالا
            ['account_code' => '120101', 'amount' => 100000000], // اثاثه
            ['account_code' => '210101', 'amount' => 20000000],  // بستانکاران
            ['account_code' => '310101', 'amount' => 165000000], // سرمایه
        ];
    }
}
```

---

## ۷. گزارش‌گیری

### ۷.۱ داشبورد مالی

```php
<?php

namespace App\Services;

use YourVendor\Accounting\Facades\Accounting;
use Carbon\Carbon;

class FinancialDashboardService
{
    public function getDashboardData(): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();
        
        return [
            'cash_balance' => $this->getCashBalance(),
            'bank_balance' => $this->getBankBalance(),
            'receivables' => $this->getTotalReceivables(),
            'payables' => $this->getTotalPayables(),
            'today_income' => $this->getIncomeForPeriod($today, $today),
            'today_expenses' => $this->getExpensesForPeriod($today, $today),
            'month_income' => $this->getIncomeForPeriod($startOfMonth, $today),
            'month_expenses' => $this->getExpensesForPeriod($startOfMonth, $today),
            'year_income' => $this->getIncomeForPeriod($startOfYear, $today),
            'year_expenses' => $this->getExpensesForPeriod($startOfYear, $today),
            'recent_documents' => $this->getRecentDocuments(10),
            'top_customers' => $this->getTopCustomers(5),
            'expense_breakdown' => $this->getExpenseBreakdown($startOfMonth, $today),
        ];
    }
    
    private function getCashBalance(): float
    {
        return Accounting::systemAccount('cash')->cached_balance;
    }
    
    private function getBankBalance(): float
    {
        return Accounting::systemAccount('bank')->cached_balance;
    }
    
    private function getTotalReceivables(): float
    {
        return Accounting::account()->findByCode('1103')->cached_balance;
    }
    
    private function getTotalPayables(): float
    {
        return abs(Accounting::account()->findByCode('2101')->cached_balance);
    }
    
    private function getIncomeForPeriod(Carbon $from, Carbon $to): float
    {
        $report = Accounting::report()->incomeStatement(
            fromDate: $from,
            toDate: $to
        );
        
        return $report['income']['total'];
    }
    
    private function getExpensesForPeriod(Carbon $from, Carbon $to): float
    {
        $report = Accounting::report()->incomeStatement(
            fromDate: $from,
            toDate: $to
        );
        
        return $report['expenses']['total'];
    }
    
    private function getRecentDocuments(int $limit)
    {
        return \YourVendor\Accounting\Models\Document::query()
            ->where('status', 'posted')
            ->with('items.account')
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->limit($limit)
            ->get();
    }
    
    private function getTopCustomers(int $limit): array
    {
        return \YourVendor\Accounting\Models\Account::query()
            ->where('entity_type', 'user')
            ->where('cached_balance', '>', 0)
            ->orderByDesc('cached_balance')
            ->limit($limit)
            ->get()
            ->map(fn($a) => [
                'name' => $a->title,
                'balance' => $a->cached_balance,
            ])
            ->toArray();
    }
    
    private function getExpenseBreakdown(Carbon $from, Carbon $to): array
    {
        $report = Accounting::report()->incomeStatement(
            fromDate: $from,
            toDate: $to
        );
        
        return collect($report['expenses']['operating']['items'] ?? [])
            ->map(fn($item) => [
                'title' => $item['title'],
                'amount' => $item['amount'],
                'percentage' => $item['percentage'] ?? 0,
            ])
            ->toArray();
    }
}
```

---

## ۸. خلاصه سناریوها

| سناریو | عملیات اصلی | حساب‌های کلیدی |
|--------|-------------|----------------|
| فروشگاه | فروش، خرید، دریافت، پرداخت | صندوق، بانک، بدهکاران، موجودی کالا |
| آموزشگاه | ثبت‌نام، شهریه، حقوق مدرس | صندوق، بدهکاران، درآمد شهریه، هزینه حقوق |
| خدماتی | صورتحساب، پروژه، هزینه | بانک، بدهکاران، درآمد خدمات |
| عمومی | انتقال، تنخواه، هزینه | صندوق، بانک، حساب‌های هزینه |
| سال مالی | افتتاحیه، اختتامیه | همه حساب‌های دائمی و موقت |

---

[→ ادامه: پیاده‌سازی - Models (14a-models.md)](14-implementation/14a-models.md)

[← بازگشت: امنیت (12-security.md)](12-security.md)

[⌂ فهرست (00-index.md)](00-index.md)
