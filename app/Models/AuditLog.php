<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;

class AuditLog extends DomainModel
{
    use AppendOnly;

    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
