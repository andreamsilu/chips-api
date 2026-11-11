<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends DomainModel
{
    use SoftDeletes;

    protected $table = 'addresses';

    protected $hidden = ['default_user_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }
}
