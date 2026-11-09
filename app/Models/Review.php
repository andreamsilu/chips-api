<?php

namespace App\Models;

use App\Support\DomainModel;

class Review extends DomainModel
{
    protected $table = 'reviews';

    protected function casts(): array
    {
        return [];
    }
}
