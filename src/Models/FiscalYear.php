<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Karnoweb\Accounting\Enums\FiscalYearStatus;
use Karnoweb\Accounting\Exceptions\FiscalYearOverlapException;
use Karnoweb\Accounting\Services\FiscalYearService;

class FiscalYear extends BaseModel
{
    use SoftDeletes;

    protected $table = 'fiscal_years';

    protected $fillable = [
        'title',
        'start_date',
        'end_date',
        'status',
        'is_current',
        'opening_done',
        'opened_at',
        'closed_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_current' => false,
        'opening_done' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => FiscalYearStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'opening_done' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FiscalYear $fiscalYear) {
            app(FiscalYearService::class)->assertNoOverlap(
                Carbon::parse($fiscalYear->start_date)->toDateString(),
                Carbon::parse($fiscalYear->end_date)->toDateString()
            );
        });

        static::updating(function (FiscalYear $fiscalYear) {
            if ($fiscalYear->isDirty(['start_date', 'end_date'])) {
                app(FiscalYearService::class)->assertNoOverlap(
                    Carbon::parse($fiscalYear->start_date)->toDateString(),
                    Carbon::parse($fiscalYear->end_date)->toDateString(),
                    $fiscalYear->id
                );
            }
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeContainingDate(Builder $query, $date): Builder
    {
        $normalized = Carbon::parse($date)->toDateString();

        return $query->whereDate('start_date', '<=', $normalized)
            ->whereDate('end_date', '>=', $normalized);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function isActive(): bool
    {
        return $this->status === FiscalYearStatus::ACTIVE;
    }

    public function isClosed(): bool
    {
        return $this->status === FiscalYearStatus::CLOSED;
    }

    public function containsDate($date): bool
    {
        $date = Carbon::parse($date);

        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Latest `documents.date` (any status) for this year, or null if it has none.
     * Delegates to the canonical `FiscalYearService::latestDocumentDate()`.
     */
    public function getLatestDocumentDateAttribute(): ?string
    {
        return app(FiscalYearService::class)->latestDocumentDate($this);
    }

    /**
     * Smallest `end_date` `update()` would currently accept for this year.
     * Delegates to the canonical `FiscalYearService::minAllowedEndDate()`.
     */
    public function getMinAllowedEndDateAttribute(): string
    {
        return app(FiscalYearService::class)->minAllowedEndDate($this);
    }

    /**
     * Active current fiscal year. Closed and draft years are never returned.
     */
    public static function current(): ?self
    {
        return static::query()
            ->where('status', FiscalYearStatus::ACTIVE)
            ->where('is_current', true)
            ->first()
            ?? static::query()->where('status', FiscalYearStatus::ACTIVE)->first();
    }

    /**
     * Fiscal year whose range contains $date. Ambiguous overlaps are rejected.
     */
    public static function findByDate($date): ?self
    {
        $normalized = Carbon::parse($date)->toDateString();
        $matches = static::containingDate($normalized)->orderBy('id')->get();

        if ($matches->count() > 1) {
            throw new FiscalYearOverlapException(
                __('accounting::accounting.messages.fiscal_year_ambiguous')
            );
        }

        return $matches->first();
    }

    /**
     * Activate this fiscal year via the canonical service (does not create opening entries).
     */
    public function activate(): self
    {
        return app(FiscalYearService::class)->activate($this);
    }

    /**
     * Close this fiscal year via the canonical service (does not create closing entries).
     */
    public function close(): self
    {
        return app(FiscalYearService::class)->close($this);
    }

    /**
     * Mark opening_done via the canonical service (does not create opening journals).
     */
    public function completeOpening(): self
    {
        return app(FiscalYearService::class)->completeOpening($this);
    }

    /**
     * Clear opening_done via the canonical service (does not void documents).
     */
    public function revertOpening(): self
    {
        return app(FiscalYearService::class)->revertOpening($this);
    }
}
