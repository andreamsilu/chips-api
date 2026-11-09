<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;

class SupportMessage extends DomainModel
{
    use AppendOnly;

    protected $table = 'support_messages';

    protected function casts(): array
    {
        return [];
    }
}
