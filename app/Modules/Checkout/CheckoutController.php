<?php

namespace App\Modules\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CheckoutController extends Controller
{
    public function cart(Request $request, CartService $service): JsonResponse
    {
        return response()->json(['data' => $this->serialize($service->read($request->user()))]);
    }

    public function item(Request $request, CartService $service): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'ulid'], 'quantity' => ['required', 'integer', 'between:0,20'], 'instructions' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->serialize($service->put($request->user(), $data))]);
    }

    public function store(CheckoutRequest $request, CheckoutService $service): OrderResource
    {
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']])->validate()['key'];
        $order = $service->checkout($request->user(), $key, $request->validated());

        return new OrderResource($order->load(['items', 'histories', 'payment', 'delivery', 'refund', 'vendor:id,name']));
    }

    public function quote(CheckoutRequest $request, CheckoutService $service): JsonResponse
    {
        return response()->json(['data' => $service->quote($request->user(), $request->validated())]);
    }

    private function serialize(Cart $cart): array
    {
        return ['id' => $cart->id, 'version' => $cart->version, 'vendor_location_id' => $cart->vendor_location_id, 'items' => $cart->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity, 'instructions' => $item->instructions, 'product' => $item->product?->only(['id', 'name', 'price', 'available'])])];
    }
}
