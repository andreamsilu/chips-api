<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends DomainModel
{
    use SoftDeletes;

    protected $table = 'addresses';

    protected function casts(): array
    {
        return [];
    }
}
