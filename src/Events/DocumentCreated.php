<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Karnoweb\Accounting\Models\Document;

class DocumentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Document $document
    ) {}
}
