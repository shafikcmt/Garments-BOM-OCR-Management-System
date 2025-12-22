<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Authenticate
{
    public function handle(Request $request, Closure $next)
    {
        // Simple auth check (replace with real auth if needed)
        if (!$request->user()) {
            abort(403, 'Unauthorized');
        }
        return $next($request);
    }
}
