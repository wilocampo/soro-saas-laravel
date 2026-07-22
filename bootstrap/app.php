<?php

use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Http\Middleware\EnsureSubscriptionAllowsPosting;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LandlordMiddleware;
use App\Http\Middleware\TenantMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Password rotation (spec 04): an expired user may only reach
            // the profile/password/logout routes until they rotate.
            EnsurePasswordIsFresh::class,
        ]);

        $middleware->alias([
            'landlord' => LandlordMiddleware::class,
            'tenant' => TenantMiddleware::class,
            // Applied to WRITE routes only: a lapsed subscription stops new
            // postings, never access to the tenant's own books (Phase 5).
            'can-post' => EnsureSubscriptionAllowsPosting::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
