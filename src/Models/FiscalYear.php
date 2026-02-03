<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Karnoweb\Accounting\Enums\FiscalYearStatus;

class FiscalYear extends Model
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
        return $query->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
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

    public static function current(): ?self
    {
        return static::where('is_current', true)->first()
            ?? static::where('status', 'active')->first();
    }

    public static function findByDate($date): ?self
    {
        return static::containingDate($date)->first();
    }
}
