<?php

namespace App\Modules\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestCheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\GuestOrderAccessToken;
use App\Models\GuestSession;
use App\Models\Order;
use App\Models\User;
use App\Modules\Administration\AdminQuery;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class GuestCheckoutController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        AdminQuery::body($request, []);
        $token = Str::random(64);
        $session = DB::transaction(function () use ($token): GuestSession {
            $user = new User;
            $user->forceFill(['name' => 'Guest customer', 'email' => null, 'password' => null, 'is_guest' => true])->save();

            return GuestSession::record(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(max(1, min(43200, (int) config('guest.session_minutes'))))]);
        });

        return response()->json(['data' => ['token' => $token, 'expires_at' => $session->expires_at]], 201)->header('Cache-Control', 'no-store');
    }

    public function quote(GuestCheckoutRequest $request, CheckoutService $service): JsonResponse
    {
        return response()->json(['data' => $service->quote($request->user(), $request->validated())]);
    }

    public function store(GuestCheckoutRequest $request, CheckoutService $service): OrderResource
    {
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']])->validate()['key'];
        $order = $service->checkout($request->user(), $key, $request->validated());
        $token = Str::random(64);
        $session = $request->attributes->get('guest_session');
        $access = DB::transaction(function () use ($order, $session, $token): GuestOrderAccessToken {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            return GuestOrderAccessToken::where('order_id', $order->id)->first()
                ?? GuestOrderAccessToken::record(['order_id' => $order->id, 'guest_session_id' => $session->id, 'token_hash' => hash('sha256', $token), 'token_ciphertext' => $token, 'expires_at' => now()->addMinutes(max(1, min(129600, (int) config('guest.order_minutes'))))]);
        }, 3);

        return (new OrderResource($order->load(['items', 'histories', 'payment', 'delivery', 'refund', 'vendor:id,name'])))->additional(['guest_order_token' => $access->token_ciphertext, 'guest_order_expires_at' => $access->expires_at]);
    }

    public function mergeCart(Request $request, CartService $service): JsonResponse
    {
        AdminQuery::body($request, []);
        abort_if($request->user()->is_guest, 403);
        $token = $request->header('X-Guest-Token');
        abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $session = GuestSession::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->firstOrFail();
        $merged = DB::transaction(function () use ($request, $session, $service): bool {
            User::whereIn('id', [$request->user()->id, $session->user_id])->orderBy('id')->lockForUpdate()->get();
            $guest = Cart::where('user_id', $session->user_id)->with('items')->first();
            if ($guest === null || $guest->items->isEmpty()) {
                return false;
            }
            $target = $service->read($request->user());
            Domain::require($target->vendor_location_id === null || $target->vendor_location_id === $guest->vendor_location_id, 'Your account has a cart from another kitchen. Your guest cart remains available when signed out.');
            foreach ($guest->items as $item) {
                $existing = $target->items->firstWhere('product_id', $item->product_id);
                $quantity = (int) ($existing->quantity ?? 0) + $item->quantity;
                $oldInstructions = trim((string) ($existing->instructions ?? ''));
                $guestInstructions = trim((string) $item->instructions);
                Domain::require($oldInstructions === '' || $guestInstructions === '' || $oldInstructions === $guestInstructions, 'These meals have different preparation notes. Both carts are unchanged.');
                Domain::require($quantity <= 20, 'Merging would exceed the cart quantity limit. Both carts are unchanged.');
                $service->put($request->user(), ['product_id' => $item->product_id, 'quantity' => $quantity, 'instructions' => ($guestInstructions !== '' ? $guestInstructions : $oldInstructions)]);
            }
            $guest->items()->delete();
            $guest->forceFill(['vendor_location_id' => null, 'version' => $guest->version + 1])->save();

            return true;
        }, 3);

        return response()->json(['data' => ['merged' => $merged]]);
    }
}
