<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    public function getTable(): string
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $table = $this->table ?? str_replace('\\', '', Str::snake(Str::plural(class_basename($this))));

        return $prefix . $table;
    }
}
