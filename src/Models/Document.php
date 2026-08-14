<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Events\DocumentCreated;
use Karnoweb\Accounting\Exceptions\DocumentNotEditableException;
use Karnoweb\Accounting\Services\DocumentService;

class Document extends BaseModel
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
        'idempotency_key',
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

            $originalStatus = self::normalizeStatus($document->getOriginal('status'));

            if ($originalStatus === DocumentStatus::POSTED) {
                if ($document->status !== DocumentStatus::VOIDED) {
                    throw new DocumentNotEditableException($document);
                }
            }

            if ($originalStatus === DocumentStatus::VOIDED) {
                throw new DocumentNotEditableException($document);
            }
        });

        static::deleting(function (Document $document) {
            if (in_array($document->status, [DocumentStatus::POSTED, DocumentStatus::VOIDED], true)) {
                throw new DocumentNotEditableException($document);
            }
        });
    }

    private static function normalizeStatus(mixed $status): ?DocumentStatus
    {
        if ($status instanceof DocumentStatus) {
            return $status;
        }

        if ($status === null || $status === '') {
            return null;
        }

        return DocumentStatus::tryFrom((string) $status);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            config('accounting.branch.model'),
            config('accounting.branch.foreign_key', 'branch_id')
        );
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

    /**
     * Canonical posting entrypoint — delegates to DocumentService (same rules as service post).
     */
    public function post(): self
    {
        return app(DocumentService::class)->post($this);
    }

    /**
     * Apply posted status after DocumentService has validated all invariants.
     * Do not call this bypassing DocumentService::post() / Document::post().
     */
    public function markAsPosted(?int $postedBy = null): self
    {
        $this->update([
            'status' => DocumentStatus::POSTED,
            'posted_at' => now(),
            'posted_by' => $postedBy,
        ]);

        return $this->refresh()->load('items.account');
    }

    public function void(string $reason = ''): self
    {
        if ( ! $this->isVoidable()) {
            throw new Exception(__('accounting::accounting.messages.document_not_voidable'));
        }

        return DB::transaction(function () use ($reason) {
            $payload = [
                'status' => DocumentStatus::VOIDED,
                'notes' => ($this->notes ?? '') . "\n\nدلیل ابطال: {$reason}",
            ];

            // Opening/closing keys must be released in the same POSTED→VOIDED write; VOIDED rows are immutable.
            if (in_array($this->type, ['opening', 'closing'], true)) {
                $payload['idempotency_key'] = null;
            }

            $this->update($payload);

            return $this;
        });
    }
}
