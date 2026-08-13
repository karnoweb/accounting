<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentNumberSequence extends BaseModel
{
    protected $table = 'document_number_sequences';

    protected $fillable = [
        'fiscal_year_id',
        'branch_id',
        'last_number',
    ];

    protected $attributes = [
        'branch_id' => 0,
        'last_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'last_number' => 'integer',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
