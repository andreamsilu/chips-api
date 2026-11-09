<?php

namespace App\Models;

use App\Support\DomainModel;
use App\Support\ProtectsSnapshots;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string,mixed> $request_payload */
class Refund extends DomainModel
{
    use ProtectsSnapshots;

    protected function snapshotFields(): array
    {
        return ['order_id', 'payment_id', 'amount', 'reason', 'requested_by'];
    }

    protected $table = 'refunds';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'first_attempt_at' => 'datetime', 'amount' => 'integer', 'request_payload' => 'encrypted:array'];
    }

    protected $hidden = ['request_payload'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
