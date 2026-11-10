<?php

namespace App\Models;

use App\Support\DomainModel;

class VendorDocument extends DomainModel
{
    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'size' => 'integer'];
    }
}
