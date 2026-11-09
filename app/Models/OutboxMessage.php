<?php

namespace App\Models;

use App\Support\DomainModel;

/** @property array<string,mixed> $payload */
class OutboxMessage extends DomainModel
{
    protected $table = 'outbox_messages';

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'datetime'];
    }
}
