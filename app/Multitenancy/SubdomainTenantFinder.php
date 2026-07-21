<?php

namespace App\Multitenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class SubdomainTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        $host = $request->getHost();

        // Extract subdomain from host
        $subdomain = $this->extractSubdomain($host);

        if (! $subdomain) {
            return null;
        }

        return Tenant::findBySubdomain($subdomain);
    }

    private function extractSubdomain(string $host): ?string
    {
        // Remove common TLDs and extract subdomain
        $parts = explode('.', $host);

        // If we have at least 3 parts (subdomain.domain.tld), get the first part
        if (count($parts) >= 3) {
            return $parts[0];
        }

        // For local development, check if it's not the main domain
        if (config('app.env') === 'local' && ! in_array($host, ['localhost', '127.0.0.1'])) {
            return $parts[0];
        }

        return null;
    }
}
