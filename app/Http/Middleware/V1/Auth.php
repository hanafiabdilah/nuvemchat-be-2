<?php

namespace App\Http\Middleware\V1;

use App\Models\Connection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Auth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Api-Key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'API key is missing'
            ], 401);
        }

        if(!Connection::where('api_key', $apiKey)->exists()){
            return response()->json([
                'message' => 'Invalid API key'
            ], 401);
        }

        return $next($request);
    }
}
