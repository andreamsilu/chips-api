<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;

class OrderHistory extends DomainModel
{
    use AppendOnly;

    protected $table = 'order_histories';

    protected function casts(): array
    {
        return [];
    }
}
