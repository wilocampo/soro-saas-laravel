<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check if we have a current tenant
        if (! currentTenant()) {
            abort(404, 'Tenant not found.');
        }

        return $next($request);
    }
}
