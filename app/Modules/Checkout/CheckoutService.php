<?php

namespace App\Modules\Checkout;

use App\Models\Cart;
use App\Models\CouponRedemption;
use App\Models\Delivery;
use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Delivery\DeliveryPricingService;
use App\Modules\Payments\PaymentOperation;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CheckoutService
{
    public function __construct(private PricingService $pricing, private DeliveryPricingService $delivery) {}

    public function checkout(User $user, string $key, array $data): Order
    {
        $data['cart_version'] = (int) $data['cart_version'];
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        $existing = IdempotencyRecord::where(['user_id' => $user->id, 'operation' => 'checkout', 'key' => $key])->first();
        if ($existing) {
            Domain::require(hash_equals($existing->request_hash, $hash), 'Idempotency key was used with different input.');

            return Order::findOrFail($existing->resource_id);
        }
        Domain::require($data['fulfilment'] === 'pickup' || $data['payment_method'] !== 'cash', 'Delivery requires mobile-money payment.');
        $preparedRoute = $this->delivery->prepare($user, $data);

        return DB::transaction(function () use ($user, $key, $data, $hash, $preparedRoute): Order {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = IdempotencyRecord::where(['user_id' => $user->id, 'operation' => 'checkout', 'key' => $key])->first();
            if ($existing) {
                Domain::require(hash_equals($existing->request_hash, $hash), 'Idempotency key was used with different input.');

                return Order::findOrFail($existing->resource_id);
            }
            Domain::require($data['fulfilment'] === 'pickup' || $data['payment_method'] !== 'cash', 'Delivery requires mobile-money payment.');
            if ($data['payment_method'] !== 'cash') {
                abort_unless(config('marketplace.gateway_url') && config('marketplace.gateway_token') && config('marketplace.webhook_secret') && str_starts_with((string) config('marketplace.webhook_url'), 'https://'), 503, 'Mobile-money service is not configured.');
            }
            $cart = Cart::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($cart->version !== $data['cart_version']) {
                throw ValidationException::withMessages(['cart_version' => 'Cart changed. Review it before checkout.']);
            }
            $pricing = $this->pricing->calculate($user, $cart, $data, $preparedRoute);
            ['lines' => $lines,'location' => $location,'vendor' => $vendor,'products' => $products,'subtotal' => $subtotal,'fee' => $fee,'snapshot' => $snapshot,'discount' => $discount,'coupon' => $coupon] = $pricing;
            if (isset($data['expected_total']) && (int) $data['expected_total'] !== $subtotal - $discount + $fee) {
                throw ValidationException::withMessages(['expected_total' => 'Prices changed. Review the updated total before paying.']);
            }
            $cash = $data['payment_method'] === 'cash';
            $order = Order::record(['user_id' => $user->id, 'vendor_id' => $vendor->id, 'vendor_location_id' => $location->id, 'fulfilment' => $data['fulfilment'], 'payment_method' => $data['payment_method'], 'payment_status' => 'pending', 'status' => $cash ? 'confirmed' : 'awaiting_payment', 'currency' => 'TZS', 'subtotal' => $subtotal, 'discount' => $discount, 'delivery_fee' => $fee, 'commission_bps' => $vendor->commission_bps, 'commission' => intdiv(($subtotal - $discount) * $vendor->commission_bps, 10000), 'total' => $subtotal - $discount + $fee, 'address_snapshot' => $snapshot, 'payment_expires_at' => $cash ? null : now()->addMinutes(15)]);
            Domain::require($order->total > 0, 'Order total must be positive.');
            foreach ($lines as $line) {
                $product = $products->get($line->product_id);
                OrderItem::record(['order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name, 'unit_price' => $product->price, 'quantity' => $line->quantity, 'line_total' => $product->price * $line->quantity, 'instructions' => $line->instructions]);
                $product->decrement('stock', $line->quantity);
            }
            if ($coupon) {
                $coupon->increment('used');
                CouponRedemption::record(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $user->id]);
            }
            if (! $cash) {
                Domain::require($order->total >= 500, 'Snippe requires a payment of at least TZS 500.');
                $contact = $snapshot['customer'];
                $names = explode(' ', trim($contact['name']), 2);
                $payload = ['payment_type' => 'mobile', 'details' => ['amount' => $order->total, 'currency' => 'TZS'], 'phone_number' => ltrim($data['phone'], '+'), 'customer' => ['firstname' => $names[0], 'lastname' => $names[1] ?? $names[0], 'email' => $contact['email']], 'webhook_url' => config('marketplace.webhook_url'), 'metadata' => ['order_id' => $order->id]];
                $payment = Payment::record(['order_id' => $order->id, 'merchant_reference' => $order->id, 'status' => 'pending', 'amount' => $order->total, 'currency' => 'TZS', 'phone' => $data['phone'], 'request_payload' => $payload]);
                DB::afterCommit(function () use ($payment): void {
                    try {
                        PaymentOperation::dispatch($payment->id)->onQueue('payments');
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }
            if ($order->fulfilment === 'delivery') {
                Delivery::record(['order_id' => $order->id, 'status' => 'unassigned']);
            }
            Domain::transition($order, $order->status, $user);
            $cart->items()->delete();
            $cart->forceFill(['vendor_location_id' => null, 'version' => $cart->version + 1])->save();
            IdempotencyRecord::record(['user_id' => $user->id, 'operation' => 'checkout', 'key' => $key, 'request_hash' => $hash, 'resource_id' => $order->id]);

            return $order;
        }, 3);
    }

    public function quote(User $user, array $data): array
    {
        Domain::require($data['fulfilment'] === 'pickup' || $data['payment_method'] !== 'cash', 'Delivery requires mobile-money payment.');
        $preparedRoute = $this->delivery->prepare($user, $data);

        return DB::transaction(function () use ($user, $data, $preparedRoute): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $cart = Cart::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            Domain::require($cart->version === (int) $data['cart_version'], 'Cart changed. Review it before checkout.');
            $p = $this->pricing->calculate($user, $cart, $data, $preparedRoute);
            $total = $p['subtotal'] - $p['discount'] + $p['fee'];
            Domain::require($data['fulfilment'] === 'pickup' || $data['payment_method'] !== 'cash', 'Delivery requires mobile-money payment.');
            Domain::require($total > 0, 'Order total must be positive.');
            Domain::require($data['payment_method'] === 'cash' || $total >= 500, 'Snippe requires a payment of at least TZS 500.');

            return ['delivery' => $p['snapshot']['delivery'] ?? null, 'cart_version' => $cart->version, 'subtotal' => $p['subtotal'], 'discount' => $p['discount'], 'delivery_fee' => $p['fee'], 'total' => $total, 'currency' => 'TZS', 'payment_available' => $data['payment_method'] === 'cash' || (bool) (config('marketplace.gateway_url') && config('marketplace.gateway_token') && config('marketplace.webhook_secret') && str_starts_with((string) config('marketplace.webhook_url'), 'https://'))];
        }, 3);
    }
}
