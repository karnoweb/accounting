<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\InvalidPostingAccountException;
use Karnoweb\Accounting\Services\AccountService;

class DocumentItem extends BaseModel
{
    protected $table = 'document_items';

    /** @var list<string> */
    private const IMMUTABLE_FIELDS = [
        'document_id',
        'account_id',
        'cost_center_id',
        'amount',
        'sign',
        'description',
        'order',
        'meta',
    ];

    protected $fillable = [
        'document_id',
        'account_id',
        'cost_center_id',
        'amount',
        'sign',
        'debit',
        'credit',
        'description',
        'order',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'sign' => 'integer',
            'order' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentItem $item) {
            $item->assertDocumentAllowsLineMutation();
            $item->assertAccountIsPostable();
        });

        static::updating(function (DocumentItem $item) {
            if ($item->isDocumentPostedOrVoided()) {
                $dirtyFinancial = collect(self::IMMUTABLE_FIELDS)
                    ->filter(fn (string $field) => $item->isDirty($field));

                if ($dirtyFinancial->isNotEmpty()) {
                    throw new DocumentNotEditableException($item->document);
                }
            } elseif ($item->isDirty('account_id')) {
                $item->assertAccountIsPostable();
            }
        });

        static::deleting(function (DocumentItem $item) {
            if ($item->isDocumentPostedOrVoided()) {
                throw new DocumentNotEditableException($item->document);
            }
        });

        static::saving(function (DocumentItem $item) {
            $item->debit = $item->sign === 1 ? $item->amount : 0;
            $item->credit = $item->sign === -1 ? $item->amount : 0;
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function getSignedAmountAttribute(): float
    {
        return (float) ($this->amount * $this->sign);
    }

    private function isDocumentPostedOrVoided(): bool
    {
        $document = $this->document()->first();

        if ( ! $document) {
            return false;
        }

        return in_array($document->status, [DocumentStatus::POSTED, DocumentStatus::VOIDED], true);
    }

    private function assertDocumentAllowsLineMutation(): void
    {
        $document = $this->document_id
            ? Document::query()->find($this->document_id)
            : null;

        if ($document && in_array($document->status, [DocumentStatus::POSTED, DocumentStatus::VOIDED], true)) {
            throw new DocumentNotEditableException($document);
        }
    }

    private function assertAccountIsPostable(): void
    {
        if ( ! $this->account_id) {
            throw new InvalidPostingAccountException(null, __('accounting::accounting.validation.account_invalid'));
        }

        app(AccountService::class)->assertPostable((int) $this->account_id);
    }
}
