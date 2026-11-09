<?php

namespace App\Modules\Checkout;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;

final class CartService
{
    public function read(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return (Cart::where('user_id', $user->id)->first() ?? Cart::record(['user_id' => $user->id]))->load('items.product');
        });
    }

    public function put(User $user, array $data): Cart
    {
        $data['quantity'] = (int) $data['quantity'];

        return DB::transaction(function () use ($user, $data): Cart {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $cart = Cart::where('user_id', $user->id)->lockForUpdate()->first() ?? Cart::record(['user_id' => $user->id]);
            if ((int) $data['quantity'] === 0) {
                $cart->items()->where('product_id', $data['product_id'])->delete();
                $cart->forceFill(['vendor_location_id' => $cart->items()->exists() ? $cart->vendor_location_id : null, 'version' => $cart->version + 1])->save();

                return $cart->load('items.product');
            }
            $product = Product::with('location.vendor')->findOrFail($data['product_id']);
            Domain::require($product->available && $product->location->accepting_orders && $product->location->vendor->status === 'approved', 'This product is unavailable.');
            Domain::require($cart->vendor_location_id === null || $cart->vendor_location_id === $product->vendor_location_id, 'A cart may contain products from one location only.');
            Domain::require($cart->items()->count() < 50 || $cart->items()->where('product_id', $product->id)->exists(), 'Cart item limit reached.');
            $item = $cart->items()->where('product_id', $product->id)->first();
            if ($data['quantity'] === 0) {
                $item?->delete();
            } elseif ($item) {
                $item->forceFill(['quantity' => $data['quantity'], 'instructions' => $data['instructions'] ?? null])->save();
            } else {
                CartItem::record(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $data['quantity'], 'instructions' => $data['instructions'] ?? null]);
            }
            $cart->forceFill(['vendor_location_id' => $cart->items()->exists() ? $product->vendor_location_id : null, 'version' => $cart->version + 1])->save();

            return $cart->load('items.product');
        }, 3);
    }
}
