<?php

namespace App\Modules\Checkout;

use App\Models\Address;
use App\Models\Campaign;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Modules\Delivery\DeliveryPricingService;
use App\Support\Domain;

final class PricingService
{
    public function __construct(private DeliveryPricingService $delivery) {}

    public function calculate(User $user, Cart $cart, array $data, ?array $preparedRoute = null): array
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
            Domain::require($preparedRoute !== null, 'Request a fresh delivery quote.');
            $delivery = $this->delivery->quote($location, $address, $preparedRoute) + ['fee_beneficiary' => 'vendor'];
            $fee = $delivery['fee'];
            $snapshot = $address->only(['label', 'details', 'latitude', 'longitude', 'formatted_address', 'google_place_id', 'landmark', 'delivery_instructions']) + ['delivery' => $delivery];
        }
        $snapshot['customer'] = $user->is_guest ? ['name' => $data['customer_name'] ?? null, 'email' => $data['customer_email'] ?? null, 'phone' => $data['phone']] : ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
        $discount = 0;
        $coupon = null;
        if (! empty($data['coupon'])) {
            $coupon = Coupon::where('code', strtoupper($data['coupon']))->lockForUpdate()->first();
            Domain::require($coupon !== null && $coupon->vendor_id === $vendor->id && $coupon->active && $coupon->starts_at <= now() && $coupon->expires_at > now() && $coupon->used < $coupon->usage_limit && $subtotal >= $coupon->minimum_subtotal, 'Coupon is invalid or unavailable.');
            Domain::require(! CouponRedemption::where('coupon_id', $coupon->id)->where('user_id', $user->id)->exists(), 'Coupon has already been used.');
            if ($coupon->campaign_id !== null) {
                $campaign = Campaign::whereKey($coupon->campaign_id)->lockForUpdate()->firstOrFail();
                Domain::require($campaign->active && $campaign->starts_at <= now() && $campaign->expires_at > now(), 'The promotion campaign is unavailable.');
            }
            $discount = min($subtotal, $coupon->discount);
        }

        return compact('lines', 'location', 'vendor', 'products', 'subtotal', 'fee', 'snapshot', 'discount', 'coupon');
    }
}
