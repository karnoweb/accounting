<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends BaseModel
{
    protected $table = 'branches';

    protected $fillable = [
        'code',
        'title',
        'is_active',
        'is_default',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
