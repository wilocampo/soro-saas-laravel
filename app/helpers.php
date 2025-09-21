<?php

use App\Models\Tenant;

if (!function_exists('currentTenant')) {
    /**
     * Get the current tenant instance.
     */
    function currentTenant(): ?Tenant
    {
        return app('currentTenant');
    }
}




