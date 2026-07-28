<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenNotRevoked
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if (! $user) {
            abort(401);
        }

        if ($user->token_valid_after !== null) {
            $issuedAt = auth('api')->payload()->get('iat');

            if ($issuedAt < $user->token_valid_after->timestamp) {
                return response()->json([
                    'success' => false,
                    'status' => 401,
                    'message' => 'Session expired due to role change. Please log in again.',
                ], 401);
            }
        }

        return $next($request);
    }
}
