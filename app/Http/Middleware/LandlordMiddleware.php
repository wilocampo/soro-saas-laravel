<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LandlordMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is accessing from main domain (landlord)
        $host = $request->getHost();
        $mainDomain = config('app.domain', 'localhost');
        
        // For local development, check if it's the main localhost
        if (config('app.env') === 'local') {
            if (!in_array($host, ['localhost', '127.0.0.1', 'soro.local'])) {
                abort(403, 'Access denied. Landlord access required.');
            }
        } else {
            // For production, check if it's the main domain
            if ($host !== $mainDomain) {
                abort(403, 'Access denied. Landlord access required.');
            }
        }
        
        return $next($request);
    }
}