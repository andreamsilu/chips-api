<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon|null $location_updated_at */
class Rider extends DomainModel
{
    protected $table = 'riders';

    protected $hidden = ['last_latitude', 'last_longitude', 'location_accuracy_meters', 'location_updated_at'];

    protected function casts(): array
    {
        return ['available' => 'boolean', 'location_updated_at' => 'datetime'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
