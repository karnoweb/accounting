<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Karnoweb\Accounting\Enums\AccountingPeriodStatus;
use Karnoweb\Accounting\Services\AccountingPeriodService;

class AccountingPeriod extends BaseModel
{
    protected $table = 'accounting_periods';

    protected $fillable = [
        'fiscal_year_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountingPeriodStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AccountingPeriodStatus::OPEN);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', AccountingPeriodStatus::CLOSED);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', AccountingPeriodStatus::DRAFT);
    }

    public function scopeForFiscalYear(Builder $query, FiscalYear|int $fiscalYear): Builder
    {
        $id = $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear;

        return $query->where('fiscal_year_id', $id);
    }

    public function scopeContainingDate(Builder $query, $date): Builder
    {
        $normalized = Carbon::parse($date)->toDateString();

        return $query->whereDate('start_date', '<=', $normalized)
            ->whereDate('end_date', '>=', $normalized);
    }

    public function isDraft(): bool
    {
        return $this->status === AccountingPeriodStatus::DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === AccountingPeriodStatus::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === AccountingPeriodStatus::CLOSED;
    }

    public function containsDate($date): bool
    {
        $date = Carbon::parse($date);

        return $date->between($this->start_date, $this->end_date);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * Canonical resolution: unique period in $fiscalYear containing $date.
     */
    public static function findByDate(FiscalYear|int $fiscalYear, $date): ?self
    {
        return app(AccountingPeriodService::class)->resolve($fiscalYear, $date);
    }

    public function open(): self
    {
        return app(AccountingPeriodService::class)->open($this);
    }

    public function close(): self
    {
        return app(AccountingPeriodService::class)->close($this);
    }
}
