<?php

namespace App\Http\Middleware;

use App\Models\GuestOrderAccessToken;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class GuestOrderAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Order-Token');
        $order = $request->route('order');
        $orderId = $order instanceof Order ? $order->id : $order;
        abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404, 'Private order access is unavailable.');
        $access = GuestOrderAccessToken::where('order_id', $orderId)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        abort_unless($access !== null, 404, 'Private order access is unavailable.');
        $ownerId = Order::whereKey($orderId)->value('user_id');
        $user = User::whereKey($ownerId)->where('is_guest', true)->whereNull('suspended_at')->first();
        abort_unless($user !== null, 404, 'Private order access is unavailable.');
        $resolver = $request->getUserResolver();
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($user);
        $request->setUserResolver(fn () => $user);
        try {
            return $next($request);
        } finally {
            $request->setUserResolver($resolver);
            $guard->forgetUser();
            if ($previous !== null) {
                $guard->setUser($previous);
            }
        }
    }
}
