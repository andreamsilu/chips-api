<?php

namespace App\Modules\Checkout;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Modules\Catalog\DiscoveryRepository;
use App\Support\Domain;

final class PricingService
{
    public function calculate(User $user, Cart $cart, array $data): array
    {
        $lines = $cart->items()->orderBy('product_id')->get();
        Domain::require($lines->isNotEmpty(), 'Cart is empty.');
        $location = VendorLocation::whereKey($cart->vendor_location_id)->lockForUpdate()->firstOrFail();
        $vendor = Vendor::whereKey($location->vendor_id)->lockForUpdate()->firstOrFail();
        Domain::require($vendor->status === 'approved' && $location->accepting_orders, 'Vendor is not accepting orders.');
        Domain::require((bool) $location->getAttribute($data['fulfilment'].'_enabled'), 'Fulfilment method is unavailable.');
        $products = Product::whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $subtotal = 0;
        foreach ($lines as $line) {
            $product = $products->get($line->product_id);
            Domain::require($product !== null && $product->available && $product->vendor_location_id === $location->id && $product->stock >= $line->quantity, 'A product is unavailable or out of stock.');
            $subtotal += $product->price * $line->quantity;
        }
        $fee = 0;
        $snapshot = ['details' => $location->address, 'latitude' => $location->latitude, 'longitude' => $location->longitude];
        if ($data['fulfilment'] === 'delivery') {
            $address = Address::where('user_id', $user->id)->findOrFail($data['address_id']);
            $distance = DiscoveryRepository::distance((float) $location->latitude, (float) $location->longitude, (float) $address->latitude, (float) $address->longitude);
            Domain::require($distance <= $location->delivery_radius_km, 'Address is outside the delivery zone.');
            $fee = config('marketplace.delivery_base_fee') + (int) ceil($distance) * config('marketplace.delivery_per_km');
            $snapshot = $address->only(['label', 'details', 'latitude', 'longitude']);
        }
        $discount = 0;
        $coupon = null;
        if (! empty($data['coupon'])) {
            $coupon = Coupon::where('code', strtoupper($data['coupon']))->lockForUpdate()->first();
            Domain::require($coupon !== null && $coupon->vendor_id === $vendor->id && $coupon->active && $coupon->starts_at <= now() && $coupon->expires_at > now() && $coupon->used < $coupon->usage_limit && $subtotal >= $coupon->minimum_subtotal, 'Coupon is invalid or unavailable.');
            Domain::require(! CouponRedemption::where('coupon_id', $coupon->id)->where('user_id', $user->id)->exists(), 'Coupon has already been used.');
            $discount = min($subtotal, $coupon->discount);
        }

        return compact('lines', 'location', 'vendor', 'products', 'subtotal', 'fee', 'snapshot', 'discount', 'coupon');
    }
}
