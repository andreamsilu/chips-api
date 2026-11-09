<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->suspended_at !== null, 403, 'Account is suspended.');

        return $next($request);
    }
}
