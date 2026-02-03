<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends BaseModel
{
    protected $table = 'document_items';

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
        static::saving(function (DocumentItem $item) {
            $item->debit = $item->sign  === 1 ? $item->amount : 0;
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
}
