<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends DomainModel
{
    use AppendOnly;

    protected $table = 'ledger_entries';

    protected function casts(): array
    {
        return [];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
