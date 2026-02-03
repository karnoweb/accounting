<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLog extends BaseModel
{
    public $timestamps = false;

    protected $table = 'document_logs';

    protected $fillable = [
        'document_id',
        'user_id',
        'action',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentLog $log) {
            $log->created_at ??= now();
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        $userModel = config('accounting.user.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }
}
