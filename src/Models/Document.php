<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Events\DocumentCreated;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Exceptions\UnbalancedDocumentException;

class Document extends Model
{
    use SoftDeletes;

    public array $_oldValues = [];

    protected $table = 'documents';

    protected $fillable = [
        'fiscal_year_id',
        'branch_id',
        'number',
        'reference',
        'date',
        'type',
        'status',
        'description',
        'notes',
        'source_type',
        'source_id',
        'posted_at',
        'created_by',
        'approved_by',
        'posted_by',
        'meta',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $dispatchesEvents = [
        'created' => DocumentCreated::class,
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'date' => 'date',
            'posted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            $document->_oldValues = $document->getOriginal();

            if ($document->getOriginal('status') === 'posted') {
                if ($document->status !== DocumentStatus::VOIDED) {
                    throw new DocumentNotEditableException($document);
                }
            }
        });

        static::deleting(function (Document $document) {
            if ($document->status === DocumentStatus::POSTED) {
                throw new DocumentNotEditableException($document);
            }
        });
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('order');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DocumentLog::class)->orderBy('created_at');
    }

    public function createdBy(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function getDebitTotalAttribute(): float
    {
        return (float) $this->items->where('sign', 1)->sum('amount');
    }

    public function getCreditTotalAttribute(): float
    {
        return (float) $this->items->where('sign', -1)->sum('amount');
    }

    public function isBalanced(): bool
    {
        $balance = $this->items->sum(fn ($item) => $item->amount * $item->sign);

        return abs($balance) < 0.01;
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::POSTED;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [DocumentStatus::DRAFT, DocumentStatus::PENDING], true);
    }

    public function isVoidable(): bool
    {
        return $this->status === DocumentStatus::POSTED;
    }

    public function post(): self
    {
        if ( ! $this->isBalanced()) {
            throw new UnbalancedDocumentException(
                $this->debit_total,
                $this->credit_total
            );
        }

        $this->update([
            'status' => DocumentStatus::POSTED,
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);

        return $this;
    }

    public function void(string $reason = ''): self
    {
        if ( ! $this->isVoidable()) {
            throw new Exception(__('accounting::accounting.messages.document_not_voidable'));
        }

        $this->update([
            'status' => DocumentStatus::VOIDED,
            'notes' => ($this->notes ?? '') . "\n\nدلیل ابطال: {$reason}",
        ]);

        return $this;
    }
}
