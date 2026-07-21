<?php

namespace App\Providers;

use App\Domain\Ledger\DatabasePostingService;
use App\Domain\Ledger\PostingService;
use App\Models\Tenant;
use App\Tenancy\Backup\DatabaseDumper;
use App\Tenancy\Backup\MariaDbDumper;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register currentTenant service
        $this->app->singleton('currentTenant', function () {
            return null; // Will be set by the multitenancy package
        });

        $this->app->bind(
            DatabaseDumper::class,
            MariaDbDumper::class,
        );

        $this->app->bind(
            PostingService::class,
            DatabasePostingService::class,
        );

        // Jobs/sessions/cache tables live on the LANDLORD connection
        // (docs/specs/10 §5). SwitchTenantDatabaseTask swaps the *default*
        // connection per tenant, so these stores must be pinned to the
        // boot-time default or a queued job's bookkeeping would follow the
        // tenant swap and land in (or read from) the wrong database.
        foreach (['queue.connections.database.connection', 'session.connection', 'cache.stores.database.connection'] as $key) {
            if (config($key) === null) {
                config([$key => config('database.default')]);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
