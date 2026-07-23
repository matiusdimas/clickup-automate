<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class CheckApiAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check for static API_BEARER_TOKEN from .env
        $staticToken = env('API_BEARER_TOKEN');
        if (!empty($staticToken) && $request->bearerToken() === $staticToken) {
            return $next($request);
        }

        // 2. Fallback to Sanctum stateful authentication (for local React dashboard)
        if (Auth::guard('sanctum')->check()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Unauthenticated. Invalid API_BEARER_TOKEN or missing session.'
        ], 401);
    }
}
