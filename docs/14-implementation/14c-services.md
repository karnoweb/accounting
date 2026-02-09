# 14-implementation/14c-services.md

# پیاده‌سازی - Services

## Implementation - Services

---

## مقدمه

این بخش شامل کد کامل Service های پکیج حسابداری است. Service ها منطق تجاری اصلی را پیاده‌سازی می‌کنند.

---

## ۱. AccountService

```php
<?php

namespace YourVendor\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\Branch;
use YourVendor\Accounting\Enums\AccountType;
use YourVendor\Accounting\Enums\AccountNature;
use YourVendor\Accounting\Exceptions\AccountNotFoundException;

class AccountService
{
    /*
    |--------------------------------------------------------------------------
    | CRUD Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new account.
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            // یافتن والد
            $parent = null;
            if (!empty($data['parent_id'])) {
                $parent = Account::findOrFail($data['parent_id']);
            } elseif (!empty($data['parent_code'])) {
                $parent = Account::where('code', $data['parent_code'])->firstOrFail();
            }

            // تعیین سطح
            $level = $parent ? $parent->level + 1 : 0;

            // تولید کد خودکار
            if (empty($data['code']) && config('accounting.account.auto_code', true)) {
                $data['code'] = $this->generateCode($parent);
            }

            // تعیین ماهیت پیش‌فرض
            if (empty($data['nature']) && !empty($data['type'])) {
                $type = $data['type'] instanceof AccountType 
                    ? $data['type'] 
                    : AccountType::from($data['type']);
                $data['nature'] = $type->defaultNature()->value;
            }

            // ایجاد حساب
            return Account::create([
                'parent_id' => $parent?->id,
                'branch_id' => $data['branch_id'] ?? null,
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'level' => $level,
                'type' => $data['type'],
                'nature' => $data['nature'],
                'is_active' => $data['is_active'] ?? true,
                'is_system' => $data['is_system'] ?? false,
                'allow_direct_posting' => $data['allow_direct_posting'] ?? ($level === 3),
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);
        });
    }

    /**
     * Update an account.
     */
    public function update(Account|int $account, array $data): Account
    {
        $account = $this->resolveAccount($account);

        $allowedFields = [
            'title',
            'description',
            'is_active',
            'allow_direct_posting',
            'meta',
        ];

        // حساب‌های غیرسیستمی فیلدهای بیشتری قابل ویرایش دارند
        if (!$account->is_system) {
            $allowedFields = array_merge($allowedFields, [
                'branch_id',
            ]);
        }

        $updateData = array_intersect_key($data, array_flip($allowedFields));

        $account->update($updateData);

        return $account->fresh();
    }

    /**
     * Delete an account.
     */
    public function delete(Account|int $account): bool
    {
        $account = $this->resolveAccount($account);

        if (!$account->canDelete()) {
            throw new \Exception(__('accounting::accounting.messages.account_cannot_delete'));
        }

        return $account->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Find Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Find account by ID.
     */
    public function find(int $id): ?Account
    {
        return Account::find($id);
    }

    /**
     * Find account by ID or fail.
     */
    public function findOrFail(int $id): Account
    {
        return Account::findOrFail($id);
    }

    /**
     * Find account by code.
     */
    public function findByCode(string $code): ?Account
    {
        return Account::where('code', $code)->first();
    }

    /**
     * Find account by code or fail.
     */
    public function findByCodeOrFail(string $code): Account
    {
        $account = $this->findByCode($code);

        if (!$account) {
            throw new AccountNotFoundException("Account with code {$code} not found");
        }

        return $account;
    }

    /**
     * Find account by entity.
     */
    public function findByEntity(string $entityType, int $entityId): ?Account
    {
        return Account::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();
    }

    /**
     * Search accounts.
     */
    public function search(array $filters): Collection
    {
        $query = Account::query();

        // جستجوی متنی
        if (!empty($filters['query'])) {
            $search = $filters['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // فیلتر نوع
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // فیلتر ماهیت
        if (!empty($filters['nature'])) {
            $query->where('nature', $filters['nature']);
        }

        // فیلتر سطح
        if (isset($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        // فیلتر والد
        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }

        // فیلتر وضعیت فعال
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // فیلتر نوع موجودیت
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        // فیلتر شعبه
        if (!empty($filters['branch_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('branch_id', $filters['branch_id'])
                  ->orWhereNull('branch_id');
            });
        }

        // فیلتر دارای مانده
        if (!empty($filters['has_balance'])) {
            $query->where('cached_balance', '!=', 0);
        }

        return $query->orderBy('code')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Tree Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Get account tree.
     */
    public function getTree(?int $parentId = null, int $maxLevel = 3): Collection
    {
        $query = Account::query()
            ->with(['children' => function ($q) use ($maxLevel) {
                $q->where('level', '<=', $maxLevel)
                  ->orderBy('code');
            }])
            ->where('level', '<=', $maxLevel)
            ->orderBy('code');

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        return $query->get();
    }

    /**
     * Get children of an account.
     */
    public function getChildren(Account|int $account): Collection
    {
        $account = $this->resolveAccount($account);
        return $account->children()->orderBy('code')->get();
    }

    /**
     * Get parent of an account.
     */
    public function getParent(Account|int $account): ?Account
    {
        $account = $this->resolveAccount($account);
        return $account->parent;
    }

    /**
     * Get all ancestors of an account.
     */
    public function getAncestors(Account|int $account): Collection
    {
        $account = $this->resolveAccount($account);
        $ancestors = collect();
        $parent = $account->parent;

        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }

        return $ancestors->reverse()->values();
    }

    /**
     * Get all descendants of an account.
     */
    public function getDescendants(Account|int $account): Collection
    {
        $account = $this->resolveAccount($account);
        return $this->collectDescendants($account);
    }

    /**
     * Recursively collect descendants.
     */
    private function collectDescendants(Account $account): Collection
    {
        $descendants = collect();

        foreach ($account->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($this->collectDescendants($child));
        }

        return $descendants;
    }

    /*
    |--------------------------------------------------------------------------
    | Code Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate next code for account.
     */
    public function generateCode(Account|int|string|null $parent = null): string
    {
        // یافتن والد
        if (is_string($parent)) {
            $parent = $this->findByCode($parent);
        } elseif (is_int($parent)) {
            $parent = $this->find($parent);
        }

        $codeLengths = config('accounting.account.code_length', [1, 2, 4, 6]);

        if ($parent === null) {
            // کد سطح صفر
            $lastCode = Account::whereNull('parent_id')
                ->orderByDesc('code')
                ->value('code');

            $nextNumber = $lastCode ? ((int) $lastCode + 1) : 1;
            return str_pad((string) $nextNumber, $codeLengths[0], '0', STR_PAD_LEFT);
        }

        // یافتن آخرین کد فرزند
        $newLevel = $parent->level + 1;
        $parentCode = $parent->code;
        $expectedLength = $codeLengths[$newLevel] ?? ($codeLengths[count($codeLengths) - 1] + 2);

        $lastChild = Account::where('parent_id', $parent->id)
            ->orderByDesc('code')
            ->first();

        if ($lastChild) {
            $lastSuffix = substr($lastChild->code, strlen($parentCode));
            $nextNumber = (int) $lastSuffix + 1;
        } else {
            $nextNumber = 1;
        }

        $suffixLength = $expectedLength - strlen($parentCode);
        $suffix = str_pad((string) $nextNumber, $suffixLength, '0', STR_PAD_LEFT);

        return $parentCode . $suffix;
    }

    /**
     * Validate account code.
     */
    public function validateCode(string $code): bool
    {
        // بررسی یکتایی
        if (Account::where('code', $code)->exists()) {
            return false;
        }

        // بررسی فرمت (فقط اعداد)
        if (!preg_match('/^\d+$/', $code)) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | System Accounts
    |--------------------------------------------------------------------------
    */

    /**
     * Get system account by key.
     */
    public function getSystemAccount(string $key): Account
    {
        $code = config("accounting.account.system_accounts.{$key}");

        if (!$code) {
            throw new \Exception("System account key '{$key}' not configured");
        }

        $account = $this->findByCode($code);

        if (!$account) {
            throw new AccountNotFoundException("System account '{$key}' with code '{$code}' not found");
        }

        return $account;
    }

    /**
     * Get all system accounts.
     */
    public function getSystemAccounts(): Collection
    {
        return Account::where('is_system', true)->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve account from ID or model.
     */
    private function resolveAccount(Account|int $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return $this->findOrFail($account);
    }

    /**
     * Get accounts by type.
     */
    public function getByType(string|AccountType $type): Collection
    {
        $typeValue = $type instanceof AccountType ? $type->value : $type;
        return Account::where('type', $typeValue)->orderBy('code')->get();
    }

    /**
     * Get postable accounts (level 3, active).
     */
    public function getPostableAccounts(?int $branchId = null): Collection
    {
        $query = Account::where('level', 3)
            ->where('is_active', true)
            ->where('allow_direct_posting', true);

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereNull('branch_id');
            });
        }

        return $query->orderBy('code')->get();
    }
}
```

---

## ۲. DocumentService

```php
<?php

namespace YourVendor\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\DocumentItem;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Models\Branch;
use YourVendor\Accounting\Enums\DocumentStatus;
use YourVendor\Accounting\Exceptions\UnbalancedDocumentException;
use YourVendor\Accounting\Exceptions\ClosedFiscalYearException;
use YourVendor\Accounting\Exceptions\InactiveAccountException;
use YourVendor\Accounting\Events\DocumentCreated;
use YourVendor\Accounting\Events\DocumentPosted;
use YourVendor\Accounting\Events\DocumentVoided;
use Carbon\Carbon;

class DocumentService
{
    public function __construct(
        private BalanceService $balanceService,
        private AccountService $accountService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | CRUD Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new document.
     */
    public function create(array $data): Document
    {
        return DB::transaction(function () use ($data) {
            // یافتن سال مالی
            $fiscalYear = $this->resolveFiscalYear($data);

            // بررسی سال مالی
            $this->validateFiscalYear($fiscalYear, $data['date']);

            // بررسی آیتم‌ها
            $this->validateItems($data['items'] ?? []);

            // تولید شماره سند
            $number = $data['number'] ?? $this->getNextNumber($fiscalYear, $data['branch_id'] ?? null);

            // ایجاد سند
            $document = Document::create([
                'fiscal_year_id' => $fiscalYear->id,
                'branch_id' => $data['branch_id'] ?? $this->getDefaultBranchId(),
                'number' => $number,
                'reference' => $data['reference'] ?? null,
                'date' => $data['date'],
                'type' => $data['type'],
                'status' => $data['status'] ?? DocumentStatus::DRAFT,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'created_by' => auth()->id(),
                'meta' => $data['meta'] ?? null,
            ]);

            // ایجاد آیتم‌ها
            $this->createItems($document, $data['items']);

            return $document->load('items.account');
        });
    }

    /**
     * Update a document.
     */
    public function update(Document|int $document, array $data): Document
    {
        $document = $this->resolveDocument($document);

        if (!$document->isEditable()) {
            throw new \Exception(__('accounting::accounting.messages.document_not_editable'));
        }

        return DB::transaction(function () use ($document, $data) {
            // بروزرسانی فیلدهای مجاز
            $updateData = array_intersect_key($data, array_flip([
                'date',
                'type',
                'description',
                'notes',
                'reference',
                'meta',
            ]));

            // بررسی تغییر تاریخ
            if (isset($updateData['date'])) {
                $fiscalYear = $this->resolveFiscalYear(['date' => $updateData['date']]);
                $this->validateFiscalYear($fiscalYear, $updateData['date']);
                $updateData['fiscal_year_id'] = $fiscalYear->id;
            }

            $document->update($updateData);

            // بروزرسانی آیتم‌ها
            if (isset($data['items'])) {
                $this->validateItems($data['items']);
                $document->items()->delete();
                $this->createItems($document, $data['items']);
            }

            return $document->fresh()->load('items.account');
        });
    }

    /**
     * Delete a document.
     */
    public function delete(Document|int $document): bool
    {
        $document = $this->resolveDocument($document);

        if (!$document->isDeletable()) {
            throw new \Exception(__('accounting::accounting.messages.document_not_deletable'));
        }

        return DB::transaction(function () use ($document) {
            $document->items()->delete();
            return $document->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Status Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Submit document for approval.
     */
    public function submit(Document|int $document): Document
    {
        $document = $this->resolveDocument($document);

        if ($document->status !== DocumentStatus::DRAFT) {
            throw new \Exception('فقط اسناد پیش‌نویس قابل ارسال هستند.');
        }

        if (!$this->isBalanced($document)) {
            throw new UnbalancedDocumentException(
                $document->debit_total,
                $document->credit_total
            );
        }

        $document->update(['status' => DocumentStatus::PENDING]);

        return $document;
    }

    /**
     * Approve a document.
     */
    public function approve(Document|int $document): Document
    {
        $document = $this->resolveDocument($document);

        if ($document->status !== DocumentStatus::PENDING) {
            throw new \Exception('فقط اسناد در انتظار قابل تأیید هستند.');
        }

        $document->update([
            'status' => DocumentStatus::APPROVED,
            'approved_by' => auth()->id(),
        ]);

        return $document;
    }

    /**
     * Reject a document.
     */
    public function reject(Document|int $document, string $reason = ''): Document
    {
        $document = $this->resolveDocument($document);

        if ($document->status !== DocumentStatus::PENDING) {
            throw new \Exception('فقط اسناد در انتظار قابل رد هستند.');
        }

        $document->update([
            'status' => DocumentStatus::DRAFT,
            'notes' => $document->notes . "\n\nدلیل رد: {$reason}",
        ]);

        return $document;
    }

    /**
     * Post a document.
     */
    public function post(Document|int $document): Document
    {
        $document = $this->resolveDocument($document);

        // بررسی وضعیت مجاز برای ثبت
        $allowedStatuses = config('accounting.document.workflow_enabled', false)
            ? [DocumentStatus::APPROVED]
            : [DocumentStatus::DRAFT, DocumentStatus::APPROVED];

        if (!in_array($document->status, $allowedStatuses)) {
            throw new \Exception('وضعیت سند برای ثبت قطعی مناسب نیست.');
        }

        // بررسی بالانس
        if (!$this->isBalanced($document)) {
            throw new UnbalancedDocumentException(
                $document->debit_total,
                $document->credit_total
            );
        }

        // بررسی سال مالی
        $this->validateFiscalYear($document->fiscalYear, $document->date);

        return DB::transaction(function () use ($document) {
            $document->update([
                'status' => DocumentStatus::POSTED,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            // بروزرسانی مانده حساب‌ها
            $this->balanceService->updateAfterDocument($document);

            event(new DocumentPosted($document));

            return $document;
        });
    }

    /**
     * Void a document.
     */
    public function void(Document|int $document, string $reason = ''): Document
    {
        $document = $this->resolveDocument($document);

        if (!$document->isVoidable()) {
            throw new \Exception(__('accounting::accounting.messages.document_not_voidable'));
        }

        return DB::transaction(function () use ($document, $reason) {
            // معکوس کردن اثر بر مانده‌ها
            $this->balanceService->reverseDocument($document);

            $document->update([
                'status' => DocumentStatus::VOIDED,
                'notes' => $document->notes . "\n\nدلیل ابطال: {$reason}",
            ]);

            event(new DocumentVoided($document, $reason));

            return $document;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Find Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Find document by ID.
     */
    public function find(int $id): ?Document
    {
        return Document::with('items.account')->find($id);
    }

    /**
     * Find document by ID or fail.
     */
    public function findOrFail(int $id): Document
    {
        return Document::with('items.account')->findOrFail($id);
    }

    /**
     * Find document by number.
     */
    public function findByNumber(int $number, ?FiscalYear $fiscalYear = null): ?Document
    {
        $fiscalYear = $fiscalYear ?? FiscalYear::current();

        return Document::where('fiscal_year_id', $fiscalYear->id)
            ->where('number', $number)
            ->with('items.account')
            ->first();
    }

    /**
     * Search documents.
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = Document::query()->with(['items.account', 'fiscalYear', 'branch']);

        // فیلتر سال مالی
        if (!empty($filters['fiscal_year_id'])) {
            $query->where('fiscal_year_id', $filters['fiscal_year_id']);
        }

        // فیلتر شعبه
        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        // فیلتر نوع
        if (!empty($filters['type'])) {
            if (is_array($filters['type'])) {
                $query->whereIn('type', $filters['type']);
            } else {
                $query->where('type', $filters['type']);
            }
        }

        // فیلتر وضعیت
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        // فیلتر بازه تاریخ
        if (!empty($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }

        // فیلتر بازه شماره
        if (!empty($filters['number_from'])) {
            $query->where('number', '>=', $filters['number_from']);
        }
        if (!empty($filters['number_to'])) {
            $query->where('number', '<=', $filters['number_to']);
        }

        // فیلتر مرجع
        if (!empty($filters['reference'])) {
            $query->where('reference', 'like', "%{$filters['reference']}%");
        }

        // فیلتر حساب
        if (!empty($filters['account_id'])) {
            $query->whereHas('items', function ($q) use ($filters) {
                $q->where('account_id', $filters['account_id']);
            });
        }

        // فیلتر مبلغ
        if (!empty($filters['min_amount'])) {
            $query->whereHas('items', function ($q) use ($filters) {
                $q->where('amount', '>=', $filters['min_amount']);
            });
        }
        if (!empty($filters['max_amount'])) {
            $query->whereHas('items', function ($q) use ($filters) {
                $q->where('amount', '<=', $filters['max_amount']);
            });
        }

        // فیلتر ایجادکننده
        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        // مرتب‌سازی
        $sortBy = $filters['sort_by'] ?? 'date';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);
        $query->orderBy('number', $sortDir);

        $perPage = $filters['per_page'] ?? config('accounting.reports.per_page', 50);

        return $query->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate document data.
     */
    public function validate(array $data): array
    {
        $errors = [];

        // بررسی تاریخ
        if (empty($data['date'])) {
            $errors['date'] = __('accounting::accounting.validation.date_required');
        }

        // بررسی نوع
        if (empty($data['type'])) {
            $errors['type'] = __('accounting::accounting.validation.type_required');
        } else {
            $allowedTypes = config('accounting.document.allowed_types', []);
            if (!empty($allowedTypes) && !in_array($data['type'], $allowedTypes)) {
                $errors['type'] = __('accounting::accounting.validation.type_invalid');
            }
        }

        // بررسی آیتم‌ها
        $minItems = config('accounting.document.min_items', 2);
        if (empty($data['items']) || count($data['items']) < $minItems) {
            $errors['items'] = __('accounting::accounting.validation.items_required', ['min' => $minItems]);
        } else {
            $itemErrors = $this->validateItemsData($data['items']);
            if (!empty($itemErrors)) {
                $errors = array_merge($errors, $itemErrors);
            }

            // بررسی بالانس
            if (empty($errors) && !$this->isBalancedData($data['items'])) {
                $totals = $this->calculateTotals($data['items']);
                $errors['balance'] = __('accounting::accounting.validation.document_not_balanced', [
                    'debit' => number_format($totals['debit']),
                    'credit' => number_format($totals['credit']),
                ]);
            }
        }

        return $errors;
    }

    /**
     * Validate items data.
     */
    private function validateItemsData(array $items): array
    {
        $errors = [];

        foreach ($items as $index => $item) {
            // بررسی حساب
            if (empty($item['account_id'])) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.validation.account_required');
                continue;
            }

            $account = Account::find($item['account_id']);

            if (!$account) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.validation.account_invalid');
                continue;
            }

            if (!$account->is_active) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.messages.account_inactive');
            }

            if ($account->level !== 3 && config('accounting.validation.check_account_level', true)) {
                $errors["items.{$index}.account_id"] = __('accounting::accounting.messages.account_not_postable');
            }

            // بررسی مبلغ
            $minAmount = config('accounting.validation.min_amount', 0.01);
            if (empty($item['amount']) || $item['amount'] < $minAmount) {
                $errors["items.{$index}.amount"] = __('accounting::accounting.validation.amount_positive');
            }

            $maxAmount = config('accounting.validation.max_amount');
            if ($maxAmount && $item['amount'] > $maxAmount) {
                $errors["items.{$index}.amount"] = __('accounting::accounting.validation.amount_max', [
                    'max' => number_format($maxAmount),
                ]);
            }

            // بررسی علامت
            if (!isset($item['sign']) || !in_array($item['sign'], [1, -1])) {
                $errors["items.{$index}.sign"] = 'علامت باید 1 یا -1 باشد.';
            }
        }

        return $errors;
    }

    /**
     * Check if document is balanced.
     */
    public function isBalanced(Document $document): bool
    {
        $balance = $document->items->sum(fn($item) => $item->amount * $item->sign);
        return abs($balance) < 0.01;
    }

    /**
     * Check if items data is balanced.
     */
    private function isBalancedData(array $items): bool
    {
        $balance = 0;
        foreach ($items as $item) {
            $balance += ($item['amount'] ?? 0) * ($item['sign'] ?? 1);
        }
        return abs($balance) < 0.01;
    }

    /**
     * Get document totals.
     */
    public function getTotal(Document $document): array
    {
        return [
            'debit' => $document->items->where('sign', 1)->sum('amount'),
            'credit' => $document->items->where('sign', -1)->sum('amount'),
            'balance' => $document->items->sum(fn($item) => $item->amount * $item->sign),
        ];
    }

    /**
     * Calculate totals from items data.
     */
    private function calculateTotals(array $items): array
    {
        $debit = 0;
        $credit = 0;

        foreach ($items as $item) {
            $amount = $item['amount'] ?? 0;
            $sign = $item['sign'] ?? 1;

            if ($sign === 1) {
                $debit += $amount;
            } else {
                $credit += $amount;
            }
        }

        return [
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $debit - $credit,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Number Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Get next document number.
     */
    public function getNextNumber(?FiscalYear $fiscalYear = null, ?int $branchId = null): int
    {
        $fiscalYear = $fiscalYear ?? FiscalYear::current();

        $query = Document::where('fiscal_year_id', $fiscalYear->id);

        if (config('accounting.branch.separate_numbering', false) && $branchId) {
            $query->where('branch_id', $branchId);
        }

        $lastNumber = $query->max('number') ?? 0;

        return $lastNumber + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create document items.
     */
    private function createItems(Document $document, array $items): void
    {
        foreach ($items as $index => $item) {
            DocumentItem::create([
                'document_id' => $document->id,
                'account_id' => $item['account_id'],
                'cost_center_id' => $item['cost_center_id'] ?? null,
                'amount' => $item['amount'],
                'sign' => $item['sign'],
                'description' => $item['description'] ?? null,
                'order' => $item['order'] ?? $index,
                'meta' => $item['meta'] ?? null,
            ]);
        }
    }

    /**
     * Validate items before create.
     */
    private function validateItems(array $items): void
    {
        $minItems = config('accounting.document.min_items', 2);

        if (count($items) < $minItems) {
            throw new \Exception(__('accounting::accounting.validation.items_required', ['min' => $minItems]));
        }

        foreach ($items as $item) {
            $account = Account::find($item['account_id']);

            if (!$account) {
                throw new \Exception(__('accounting::accounting.validation.account_invalid'));
            }

            if (!$account->is_active && config('accounting.validation.check_account_active', true)) {
                throw new InactiveAccountException($account);
            }
        }

        // بررسی بالانس
        if (config('accounting.validation.strict_balance', true) && !$this->isBalancedData($items)) {
            $totals = $this->calculateTotals($items);
            throw new UnbalancedDocumentException($totals['debit'], $totals['credit']);
        }
    }

    /**
     * Resolve fiscal year from data.
     */
    private function resolveFiscalYear(array $data): FiscalYear
    {
        if (!empty($data['fiscal_year_id'])) {
            return FiscalYear::findOrFail($data['fiscal_year_id']);
        }

        if (config('accounting.fiscal_year.auto_detect', true) && !empty($data['date'])) {
            $fiscalYear = FiscalYear::findByDate($data['date']);
            if ($fiscalYear) {
                return $fiscalYear;
            }
        }

        $current = FiscalYear::current();
        if ($current) {
            return $current;
        }

        throw new \Exception('سال مالی یافت نشد.');
    }

    /**
     * Validate fiscal year.
     */
    private function validateFiscalYear(FiscalYear $fiscalYear, $date): void
    {
        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        if (!$fiscalYear->isActive()) {
            throw new \Exception('سال مالی فعال نیست.');
        }

        if (config('accounting.validation.check_date_range', true)) {
            if (!$fiscalYear->containsDate($date)) {
                throw new \Exception(__('accounting::accounting.validation.date_out_of_fiscal_year'));
            }
        }
    }

    /**
     * Get default branch ID.
     */
    private function getDefaultBranchId(): ?int
    {
        if (!config('accounting.branch.enabled', true)) {
            return null;
        }

        $resolver = config('accounting.branch.resolver');
        if ($resolver && is_callable($resolver)) {
            return $resolver();
        }

        return config('accounting.branch.default_id');
    }

    /**
     * Resolve document from ID or model.
     */
    private function resolveDocument(Document|int $document): Document
    {
        if ($document instanceof Document) {
            return $document;
        }

        return $this->findOrFail($document);
    }
}
```

---

## ۳. BalanceService

```php
<?php

namespace YourVendor\Accounting\Services;

use Illuminate\Support\Facades\DB;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Models\DocumentItem;
use YourVendor\Accounting\Models\FiscalYear;
use Carbon\Carbon;

class BalanceService
{
    /*
    |--------------------------------------------------------------------------
    | Balance Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Get balance of an account.
     */
    public function getBalance(
        Account|int $account,
        ?FiscalYear $fiscalYear = null,
        bool $forceRealtime = false
    ): float {
        $account = $this->resolveAccount($account);

        // استفاده از cache اگر معتبر باشد
        if (!$forceRealtime && $this->isCacheValid($account)) {
            return (float) $account->cached_balance;
        }

        return $this->calculateRealtime($account, $fiscalYear);
    }

    /**
     * Calculate real-time balance.
     */
    public function calculateRealtime(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('COALESCE(SUM(amount * sign), 0) as balance')
            ->value('balance');
    }

    /**
     * Get balance as of specific date.
     */
    public function getBalanceAsOf(
        Account|int $account,
        Carbon|string $date,
        ?FiscalYear $fiscalYear = null
    ): float {
        $account = $this->resolveAccount($account);
        $date = Carbon::parse($date);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($date, $fiscalYear) {
                $q->where('status', 'posted')
                  ->where('date', '<=', $date);
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->selectRaw('COALESCE(SUM(amount * sign), 0) as balance')
            ->value('balance');
    }

    /**
     * Get debit total.
     */
    public function getDebitTotal(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->where('sign', 1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Get credit total.
     */
    public function getCreditTotal(Account|int $account, ?FiscalYear $fiscalYear = null): float
    {
        $account = $this->resolveAccount($account);

        $query = DocumentItem::query()
            ->where('account_id', $account->id)
            ->where('sign', -1)
            ->whereHas('document', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Get turnover for period.
     */
    public function getTurnover(
        Account|int $account,
        Carbon|string $fromDate,
        Carbon|string $toDate
    ): array {
        $account = $this->resolveAccount($account);
        $fromDate = Carbon::parse($fromDate);
        $toDate = Carbon::parse($toDate);

        $result = DocumentItem::query()
            ->where('account_id', $account->id)
            ->whereHas('document', function ($q) use ($fromDate, $toDate) {
                $q->where('status', 'posted')
                  ->whereBetween('date', [$fromDate, $toDate]);
            })
            ->selectRaw('
                COALESCE(SUM(CASE WHEN sign = 1 THEN amount ELSE 0 END), 0) as debit,
                COALESCE(SUM(CASE WHEN sign = -1 THEN amount ELSE 0 END), 0) as credit
            ')
            ->first();

        return [
            'debit' => (float) $result->debit,
            'credit' => (float) $result->credit,
            'balance' => (float) ($result->debit - $result->credit),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Management
    |--------------------------------------------------------------------------
    */

    /**
     * Refresh cache for an account.
     */
    public function refreshCache(Account|int $account): float
    {
        $account = $this->resolveAccount($account);
        $balance = $this->calculateRealtime($account);

        $account->update([
            'cached_balance' => $balance,
            'balance_updated_at' => now(),
        ]);

        return $balance;
    }

    /**
     * Refresh all caches.
     */
    public function refreshAllCaches(?FiscalYear $fiscalYear = null): void
    {
        // ابتدا حساب‌های سطح ۳
        Account::where('level', 3)
            ->chunk(100, function ($accounts) use ($fiscalYear) {
                foreach ($accounts as $account) {
                    $balance = $this->calculateRealtime($account, $fiscalYear);
                    $account->update([
                        'cached_balance' => $balance,
                        'balance_updated_at' => now(),
                    ]);
                }
            });

        // سپس Roll-up به سطوح بالاتر
        $this->refreshParentBalances();
    }

    /**
     * Refresh parent balances (roll-up).
     */
    public function refreshParentBalances(): void
    {
        // از پایین به بالا
        for ($level = 2; $level >= 0; $level--) {
            DB::statement("
                UPDATE accounts p
                SET cached_balance = (
                    SELECT COALESCE(SUM(c.cached_balance), 0)
                    FROM accounts c
                    WHERE c.parent_id = p.id
                ),
                balance_updated_at = NOW()
                WHERE p.level = ?
            ", [$level]);
        }
    }

    /**
     * Invalidate cache for an account.
     */
    public function invalidateCache(Account|int $account): void
    {
        $account = $this->resolveAccount($account);
        $account->update(['balance_updated_at' => null]);
    }

    /**
     * Check if cache is valid.
     */
    private function isCacheValid(Account $account): bool
    {
        if (!config('accounting.balance.cache_enabled', true)) {
            return false;
        }

        if (!$account->balance_updated_at) {
            return false;
        }

        $ttl = config('accounting.balance.cache_ttl', 3600);
        return $account->balance_updated_at->diffInSeconds(now()) < $ttl;
    }

    /*
    |--------------------------------------------------------------------------
    | Document Effects
    |--------------------------------------------------------------------------
    */

    /**
     * Update balances after document post.
     */
    public function updateAfterDocument(Document $document): void
    {
        $strategy = config('accounting.balance.update_strategy', 'immediate');

        if ($strategy === 'immediate') {
            $this->updateImmediately($document);
        } elseif ($strategy === 'delayed') {
            dispatch(new \YourVendor\Accounting\Jobs\UpdateBalancesJob($document));
        }
        // 'scheduled' strategy is handled by cron
    }

    /**
     * Update balances immediately.
     */
    private function updateImmediately(Document $document): void
    {
        $affectedAccountIds = $document->items->pluck('account_id')->unique();

        foreach ($affectedAccountIds as $accountId) {
            $account = Account::find($accountId);
            if (!$account) continue;

            // محاسبه تغییر
            $delta = $document->items
                ->where('account_id', $accountId)
                ->sum(fn($item) => $item->amount * $item->sign);

            // بروزرسانی Atomic
            Account::where('id', $accountId)->update([
                'cached_balance' => DB::raw("cached_balance + {$delta}"),
                'balance_updated_at' => now(),
            ]);

            // بروزرسانی والدین
            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, $delta);
            }
        }
    }

    /**
     * Reverse document effect on balances.
     */
    public function reverseDocument(Document $document): void
    {
        $affectedAccountIds = $document->items->pluck('account_id')->unique();

        foreach ($affectedAccountIds as $accountId) {
            $account = Account::find($accountId);
            if (!$account) continue;

            // محاسبه تغییر معکوس
            $delta = $document->items
                ->where('account_id', $accountId)
                ->sum(fn($item) => $item->amount * $item->sign);

            // بروزرسانی با علامت معکوس
            Account::where('id', $accountId)->update([
                'cached_balance' => DB::raw("cached_balance - {$delta}"),
                'balance_updated_at' => now(),
            ]);

            // بروزرسانی والدین
            if (config('accounting.balance.update_parents', true)) {
                $this->updateParentChain($account, -$delta);
            }
        }
    }

    /**
     * Update parent chain balances.
     */
    private function updateParentChain(Account $account, float $delta): void
    {
        $parent = $account->parent;

        while ($parent) {
            Account::where('id', $parent->id)->update([
                'cached_balance' => DB::raw("cached_balance + {$delta}"),
                'balance_updated_at' => now(),
            ]);

            $parent = $parent->parent;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Balance Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if balance is normal.
     */
    public function hasNormalBalance(Account|int $account): bool
    {
        $account = $this->resolveAccount($account);
        $balance = $this->getBalance($account);

        // دارایی و هزینه باید بدهکار باشند
        if (in_array($account->type->value, ['asset', 'expense'])) {
            return $balance >= 0;
        }

        // بدهی، سرمایه و درآمد باید بستانکار باشند
        return $balance <= 0;
    }

    /**
     * Get balance warning.
     */
    public function getBalanceWarning(Account|int $account): ?string
    {
        $account = $this->resolveAccount($account);

        if ($this->hasNormalBalance($account)) {
            return null;
        }

        return __('accounting::accounting.messages.abnormal_balance', [
            'account' => $account->title,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve account from ID or model.
     */
    private function resolveAccount(Account|int $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return Account::findOrFail($account);
    }
}
```

---

## ۴. FiscalYearService

```php
<?php

namespace YourVendor\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use YourVendor\Accounting\Models\FiscalYear;
use YourVendor\Accounting\Models\Account;
use YourVendor\Accounting\Models\Document;
use YourVendor\Accounting\Enums\FiscalYearStatus;
use Carbon\Carbon;

class FiscalYearService
{
    public function __construct(
        private AccountService $accountService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | CRUD Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new fiscal year.
     */
    public function create(array $data): FiscalYear
    {
        // بررسی تداخل
        $overlap = FiscalYear::where(function ($q) use ($data) {
            $q->whereBetween('start_date', [$data['start_date'], $data['end_date']])
              ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']]);
        })->exists();

        if ($overlap) {
            throw new \Exception('تداخل با سال مالی دیگر وجود دارد.');
        }

        return FiscalYear::create([
            'title' => $data['title'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $data['status'] ?? FiscalYearStatus::DRAFT,
        ]);
    }

    /**
     * Update a fiscal year.
     */
    public function update(FiscalYear|int $fiscalYear, array $data): FiscalYear
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        // فقط draft قابل ویرایش کامل است
        if ($fiscalYear->status !== FiscalYearStatus::DRAFT) {
            $data = array_intersect_key($data, array_flip(['title']));
        }

        $fiscalYear->update($data);

        return $fiscalYear->fresh();
    }

    /**
     * Delete a fiscal year.
     */
    public function delete(FiscalYear|int $fiscalYear): bool
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->documents()->exists()) {
            throw new \Exception('سال مالی دارای سند است و قابل حذف نیست.');
        }

        return $fiscalYear->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Status Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Activate a fiscal year.
     */
    public function activate(FiscalYear|int $fiscalYear): FiscalYear
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->status !== FiscalYearStatus::DRAFT) {
            throw new \Exception('فقط سال مالی پیش‌نویس قابل فعال‌سازی است.');
        }

        return DB::transaction(function () use ($fiscalYear) {
            // غیرفعال کردن سال‌های فعال دیگر
            FiscalYear::where('status', 'active')
                ->update(['status' => FiscalYearStatus::CLOSED, 'is_current' => false]);

            $fiscalYear->update([
                'status' => FiscalYearStatus::ACTIVE,
                'is_current' => true,
                'opened_at' => now(),
            ]);

            return $fiscalYear;
        });
    }

    /**
     * Close a fiscal year.
     */
    public function close(FiscalYear|int $fiscalYear): FiscalYear
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->status !== FiscalYearStatus::ACTIVE) {
            throw new \Exception('فقط سال مالی فعال قابل بستن است.');
        }

        // بررسی اسناد پیش‌نویس
        $draftCount = $fiscalYear->documents()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            throw new \Exception("تعداد {$draftCount} سند پیش‌نویس وجود دارد.");
        }

        $fiscalYear->update([
            'status' => FiscalYearStatus::CLOSED,
            'is_current' => false,
            'closed_at' => now(),
        ]);

        return $fiscalYear;
    }

    /**
     * Reopen a fiscal year.
     */
    public function reopen(FiscalYear|int $fiscalYear): FiscalYear
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->status !== FiscalYearStatus::CLOSED) {
            throw new \Exception('فقط سال مالی بسته قابل بازگشایی است.');
        }

        return DB::transaction(function () use ($fiscalYear) {
            FiscalYear::where('status', 'active')
                ->update(['status' => FiscalYearStatus::CLOSED, 'is_current' => false]);

            $fiscalYear->update([
                'status' => FiscalYearStatus::ACTIVE,
                'is_current' => true,
                'closed_at' => null,
            ]);

            return $fiscalYear;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Opening & Closing Entries
    |--------------------------------------------------------------------------
    */

    /**
     * Create opening entry.
     */
    public function createOpening(FiscalYear|int $fiscalYear, ?FiscalYear $previousYear = null): Document
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->opening_done) {
            throw new \Exception('سند افتتاحیه قبلاً ثبت شده است.');
        }

        // یافتن سال قبل
        if (!$previousYear) {
            $previousYear = FiscalYear::where('end_date', '<', $fiscalYear->start_date)
                ->orderByDesc('end_date')
                ->first();
        }

        if (!$previousYear) {
            throw new \Exception('سال مالی قبلی یافت نشد.');
        }

        return DB::transaction(function () use ($fiscalYear, $previousYear) {
            $items = [];

            // دریافت حساب‌های دائمی
            $permanentAccounts = Account::whereIn('type', ['asset', 'liability', 'equity'])
                ->where('level', 3)
                ->get();

            foreach ($permanentAccounts as $account) {
                $balance = app(BalanceService::class)
                    ->calculateRealtime($account, $previousYear);

                if (abs($balance) < 0.01) {
                    continue;
                }

                $items[] = [
                    'account_id' => $account->id,
                    'amount' => abs($balance),
                    'sign' => $balance > 0 ? 1 : -1,
                    'description' => 'مانده از سال قبل',
                ];
            }

            if (empty($items)) {
                throw new \Exception('مانده‌ای برای انتقال وجود ندارد.');
            }

            $document = app(DocumentService::class)->create([
                'fiscal_year_id' => $fiscalYear->id,
                'date' => $fiscalYear->start_date,
                'type' => 'opening',
                'description' => "سند افتتاحیه سال مالی {$fiscalYear->title}",
                'items' => $items,
            ]);

            app(DocumentService::class)->post($document);

            $fiscalYear->update([
                'opening_done' => true,
                'opened_at' => now(),
            ]);

            return $document;
        });
    }

    /**
     * Create closing entry.
     */
    public function createClosing(FiscalYear|int $fiscalYear): Document
    {
        $fiscalYear = $this->resolveFiscalYear($fiscalYear);

        if ($fiscalYear->status !== FiscalYearStatus::ACTIVE) {
            throw new \Exception('فقط سال مالی فعال قابل اختتامیه است.');
        }

        return DB::transaction(function () use ($fiscalYear) {
            $balanceService = app(BalanceService::class);
            $items = [];

            // بستن حساب‌های درآمد
            $incomeAccounts = Account::where('type', 'income')
                ->where('level', 3)
                ->get();

            foreach ($incomeAccounts as $account) {
                $balance = $balanceService->calculateRealtime($account, $fiscalYear);
                if (abs($balance) >= 0.01) {
                    $items[] = [
                        'account_id' => $account->id,
                        'amount' => abs($balance),
                        'sign' => 1, // بدهکار کردن درآمد
                        'description' => 'بستن حساب درآمد',
                    ];
                }
            }

            // بستن حساب‌های هزینه
            $expenseAccounts = Account::where('type', 'expense')
                ->where('level', 3)
                ->get();

            foreach ($expenseAccounts as $account) {
                $balance = $balanceService->calculateRealtime($account, $fiscalYear);
                if (abs($balance) >= 0.01) {
                    $items[] = [
                        'account_id' => $account->id,
                        'amount' => abs($balance),
                        'sign' => -1, // بستانکار کردن هزینه
                        'description' => 'بستن حساب هزینه',
                    ];
                }
            }

            // محاسبه سود/زیان
            $totalIncome = collect($items)
                ->filter(fn($i) => $i['sign'] === 1)
                ->sum('amount');

            $totalExpenses = collect($items)
                ->filter(fn($i) => $i['sign'] === -1)
                ->sum('amount');

            $netProfit = $totalIncome - $totalExpenses;

            // انتقال به سود انباشته
            $retainedEarnings = $this->accountService->getSystemAccount('retained_earnings');

            $items[] = [
                'account_id' => $retainedEarnings->id,
                'amount' => abs($netProfit),
                'sign' => $netProfit >= 0 ? -1 : 1,
                'description' => $netProfit >= 0 ? 'سود خالص دوره' : 'زیان خالص دوره',
            ];

            $document = app(DocumentService::class)->create([
                'fiscal_year_id' => $fiscalYear->id,
                'date' => $fiscalYear->end_date,
                'type' => 'closing',
                'description' => "سند اختتامیه سال مالی {$fiscalYear->title}",
                'items' => $items,
            ]);

            app(DocumentService::class)->post($document);

            return $document;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Find Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Get current fiscal year.
     */
    public function current(): ?FiscalYear
    {
        return FiscalYear::current();
    }

    /**
     * Find by date.
     */
    public function findByDate(Carbon|string $date): ?FiscalYear
    {
        return FiscalYear::findByDate($date);
    }

    /**
     * Get all fiscal years.
     */
    public function all(): Collection
    {
        return FiscalYear::orderByDesc('start_date')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve fiscal year.
     */
    private function resolveFiscalYear(FiscalYear|int $fiscalYear): FiscalYear
    {
        if ($fiscalYear instanceof FiscalYear) {
            return $fiscalYear;
        }

        return FiscalYear::findOrFail($fiscalYear);
    }
}
```

---

## ۵. خلاصه Service ها

| Service | مسئولیت |
|---------|---------|
| AccountService | CRUD حساب‌ها، جستجو، درخت، کد |
| DocumentService | CRUD اسناد، تغییر وضعیت، اعتبارسنجی |
| BalanceService | محاسبه مانده، Cache، بروزرسانی |
| FiscalYearService | مدیریت سال مالی، افتتاحیه، اختتامیه |

---

[→ ادامه: پیاده‌سازی - Traits (14d-traits.md)](14d-traits.md)

[← بازگشت: پیاده‌سازی - Migrations (14b-migrations.md)](14b-migrations.md)

[⌂ فهرست (00-index.md)](../00-index.md)
