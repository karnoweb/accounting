<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    protected $table = 'cost_centers';

    protected $fillable = [
        'code',
        'title',
        'description',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function documentItems(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }
}
