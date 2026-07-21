<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Tenancy\ProvisionTenant;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::with('users')->paginate(10);

        return Inertia::render('Tenants/Index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tenants/Create');
    }

    public function store(Request $request, ProvisionTenant $provision)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'required|string|max:255|unique:tenants,subdomain|alpha_dash',
            'domain' => 'required|string|max:255|unique:tenants,domain',
            'is_active' => 'boolean',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|max:255',
            'admin_password' => 'required|string|min:8',
        ]);

        $tenant = Tenant::create([
            'name' => $request->name,
            'subdomain' => $request->subdomain,
            'domain' => $request->domain,
            'database' => 'tenant_'.str_replace('-', '_', $request->subdomain),
            'is_active' => $request->boolean('is_active', true),
            'settings' => $request->settings ?? [],
        ]);

        try {
            $provision($tenant, [
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => $request->admin_password,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('tenants.index')
                ->with('error', 'Tenant created but provisioning failed — see logs. It can be retried.');
        }

        return redirect()->route('tenants.index')
            ->with('success', 'Tenant created and provisioned successfully.');
    }

    public function show(Tenant $tenant): Response
    {
        $tenant->load('users');

        return Inertia::render('Tenants/Show', [
            'tenant' => $tenant,
        ]);
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Tenants/Edit', [
            'tenant' => $tenant,
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'required|string|max:255|alpha_dash|unique:tenants,subdomain,'.$tenant->id,
            'domain' => 'required|string|max:255|unique:tenants,domain,'.$tenant->id,
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

    public function destroy(Tenant $tenant, TenantDatabaseManager $databases)
    {
        // NOTE: offboarding must eventually export + honor retention/legal-hold
        // before dropping (docs/specs/10 §2); Phase 0 keeps the direct drop.
        $databases->dropDatabase($tenant);

        $tenant->delete();

        return redirect()->route('tenants.index')
            ->with('success', 'Tenant deleted successfully.');
    }
}
