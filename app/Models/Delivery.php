<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends DomainModel
{
    protected $table = 'deliveries';

    protected function casts(): array
    {
        return ['picked_up_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Rider, $this> */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}
