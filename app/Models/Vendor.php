<?php

namespace App\Models;

use App\Support\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends DomainModel
{
    use SoftDeletes;

    protected $table = 'vendors';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vendor_members');
    }

    /** @return HasMany<VendorLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(VendorLocation::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<VendorDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }
}
