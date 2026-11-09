<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $table = 'roles';

    protected function casts(): array
    {
        return [];
    }
}
