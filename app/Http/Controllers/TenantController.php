<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::with('users')->paginate(10);
        
        return Inertia::render('Tenants/Index', [
            'tenants' => $tenants
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tenants/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'required|string|max:255|unique:tenants,subdomain|alpha_dash',
            'domain' => 'required|string|max:255|unique:tenants,domain',
            'is_active' => 'boolean',
        ]);

        $tenant = Tenant::create([
            'name' => $request->name,
            'subdomain' => $request->subdomain,
            'domain' => $request->domain,
            'database' => 'tenant_' . str_replace('-', '_', $request->subdomain),
            'is_active' => $request->boolean('is_active', true),
            'settings' => $request->settings ?? [],
        ]);

        // Create tenant database
        $this->createTenantDatabase($tenant);

        return redirect()->route('tenants.index')
            ->with('success', 'Tenant created successfully.');
    }

    public function show(Tenant $tenant): Response
    {
        $tenant->load('users');
        
        return Inertia::render('Tenants/Show', [
            'tenant' => $tenant
        ]);
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Tenants/Edit', [
            'tenant' => $tenant
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'required|string|max:255|alpha_dash|unique:tenants,subdomain,' . $tenant->id,
            'domain' => 'required|string|max:255|unique:tenants,domain,' . $tenant->id,
            'is_active' => 'boolean',
        ]);

        $tenant->update([
            'name' => $request->name,
            'subdomain' => $request->subdomain,
            'domain' => $request->domain,
            'is_active' => $request->boolean('is_active', true),
            'settings' => $request->settings ?? $tenant->settings,
        ]);

        return redirect()->route('tenants.index')
            ->with('success', 'Tenant updated successfully.');
    }

    public function destroy(Tenant $tenant)
    {
        // Drop tenant database
        $this->dropTenantDatabase($tenant);
        
        $tenant->delete();

        return redirect()->route('tenants.index')
            ->with('success', 'Tenant deleted successfully.');
    }

    private function createTenantDatabase(Tenant $tenant): void
    {
        $databaseName = $tenant->getDatabaseName();

        // CREATE DATABASE must run on the server connection (mariadb — D22),
        // never the app default (sqlite in dev has no such statement).
        DB::connection($this->templateConnection())
            ->statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}`");

        // Run migrations for the tenant database
        $this->runTenantMigrations($tenant);
    }

    private function dropTenantDatabase(Tenant $tenant): void
    {
        $databaseName = $tenant->getDatabaseName();

        DB::connection($this->templateConnection())
            ->statement("DROP DATABASE IF EXISTS `{$databaseName}`");
    }

    private function runTenantMigrations(Tenant $tenant): void
    {
        $databaseName = $tenant->getDatabaseName();

        // Set the tenant database connection from the engine template
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.'.$this->templateConnection()),
                ['database' => $databaseName]
            )
        ]);

        // Tenant DBs receive ONLY the tenant migration set (specs/01) —
        // never the landlord set (tenants/cache/jobs/telescope).
        \Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    private function templateConnection(): string
    {
        return config('multitenancy.tenant_database_template_connection', 'mariadb');
    }
}