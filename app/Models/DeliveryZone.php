<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeliveryZone extends DomainModel
{
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'fee' => 'integer', 'maximum_distance_meters' => 'integer'];
    }
}
