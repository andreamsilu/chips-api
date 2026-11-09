<?php

namespace App\Models;

use App\Support\DomainModel;

class IdempotencyRecord extends DomainModel
{
    protected $table = 'idempotency_records';

    protected function casts(): array
    {
        return [];
    }
}
