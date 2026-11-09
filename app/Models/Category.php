<?php

namespace App\Models;

use App\Support\DomainModel;

class Category extends DomainModel
{
    protected $table = 'categories';

    protected function casts(): array
    {
        return [];
    }
}
