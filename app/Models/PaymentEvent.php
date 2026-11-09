<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;

class PaymentEvent extends DomainModel
{
    use AppendOnly;

    protected $table = 'payment_events';

    protected function casts(): array
    {
        return [];
    }
}
