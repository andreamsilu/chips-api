<?php

namespace App\Models;

use App\Support\DomainModel;

class SystemSetting extends DomainModel
{
    protected $primaryKey = 'key';

    protected function casts(): array
    {
        return ['value' => 'json', 'version' => 'integer'];
    }
}
