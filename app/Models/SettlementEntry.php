<?php

namespace App\Models;

use App\Support\AppendOnly;
use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementEntry extends DomainModel
{
    /** @return BelongsTo<Settlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    use AppendOnly;

    protected $table = 'settlement_entries';

    protected function casts(): array
    {
        return [];
    }
}
