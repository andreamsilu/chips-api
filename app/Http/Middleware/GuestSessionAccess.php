<?php

namespace App\Http\Middleware;

use App\Models\GuestSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class GuestSessionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Guest-Token');
        abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 401, 'Guest session is unavailable. Start a new cart.');
        $session = GuestSession::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        abort_unless($session !== null, 401, 'Guest session expired. Start a new cart.');
        $user = $session->user()->where('is_guest', true)->whereNull('suspended_at')->first();
        abort_unless($user !== null, 401, 'Guest session is unavailable.');
        $request->attributes->set('guest_session', $session);
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
