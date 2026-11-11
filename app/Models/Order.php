<?php

namespace App\Models;

use App\Support\DomainModel;
use App\Support\ProtectsSnapshots;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property array|null $address_snapshot */
class Order extends DomainModel
{
    use ProtectsSnapshots;

    protected function snapshotFields(): array
    {
        return ['user_id', 'vendor_id', 'vendor_location_id', 'fulfilment', 'payment_method', 'currency', 'subtotal', 'discount', 'delivery_fee', 'commission_bps', 'commission', 'total', 'address_snapshot'];
    }

    protected $table = 'orders';

    protected function casts(): array
    {
        return ['address_snapshot' => 'array', 'payment_expires_at' => 'datetime', 'stock_released_at' => 'datetime', 'cash_collected_at' => 'datetime'];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(OrderHistory::class);
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** @return HasOne<Refund, $this> */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    /** @return HasOne<Delivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<VendorLocation, $this> */
    public function vendorLocation(): BelongsTo
    {
        return $this->belongsTo(VendorLocation::class);
    }
}
