<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends DomainModel
{
    protected $table = 'support_tickets';

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }
}
