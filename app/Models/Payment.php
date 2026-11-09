<?php

namespace App\Models;

use App\Support\DomainModel;
use App\Support\ProtectsSnapshots;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string,mixed> $request_payload */
class Payment extends DomainModel
{
    use ProtectsSnapshots;

    protected function snapshotFields(): array
    {
        return ['order_id', 'merchant_reference', 'amount', 'currency', 'phone', 'request_payload'];
    }

    protected $table = 'payments';

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'request_payload' => 'encrypted:array', 'first_attempt_at' => 'datetime', 'amount' => 'integer', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    protected $hidden = ['phone', 'request_payload'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
