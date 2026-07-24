<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\ProvisionTenant;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provision a local demo tenant for manual end-to-end testing.
 *
 * This exists so the walkthrough in `docs/e2e-test-plan.md` starts from a
 * known state: one tenant, a full chart of accounts, an open fiscal year,
 * and one login per role so the permission boundaries can actually be
 * exercised rather than taken on trust.
 *
 * It creates accounts with PUBLISHED, WELL-KNOWN passwords, which is exactly
 * the kind of thing that must never reach a real deployment — hence the
 * production guard below. `app:production-check` would not catch this for
 * you; a seeded known credential looks like an ordinary user.
 */
class TenantsDemoCommand extends Command
{
    protected $signature = 'tenants:demo
        {--fresh : Drop the demo database and rebuild it from scratch}
        {--subdomain=soro : Subdomain the tenant answers on}
        {--password=password : Password for every demo login}';

    protected $description = 'Provision a local demo tenant with one login per role (local/dev only)';

    /** Role → display name. One login each so RBAC can be tested for real. */
    private const DEMO_USERS = [
        'owner' => 'Olivia Owner',
        'accountant' => 'Andrea Accountant',
        'bookkeeper' => 'Ben Bookkeeper',
        'auditor' => 'Aya Auditor',
    ];

    public function handle(TenantDatabaseManager $databases, ProvisionTenant $provision): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production: this seeds accounts with a published password.');

            return self::FAILURE;
        }

        $subdomain = (string) $this->option('subdomain');
        $password = (string) $this->option('password');
        $database = 'soro_tenant_'.$subdomain;

        $existing = Tenant::where('subdomain', $subdomain)->first();

        if ($existing !== null && ! $this->option('fresh')) {
            $this->warn("Tenant [{$subdomain}] already exists. Re-run with --fresh to rebuild it.");
            $this->credentials($subdomain, $password);

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $this->line('Dropping the existing demo database…');
            $databases->dropDatabase($existing);
            $existing->delete();
        }

        $tenant = Tenant::create([
            'name' => 'Demo Trading Corp.',
            'domain' => $subdomain.'.local',
            'subdomain' => $subdomain,
            'database' => $database,
            'is_active' => true,
        ]);

        // Without a trial the tenant cannot POST: billing is enabled by
        // default outside SINGLE_TENANT, so `can-post` answers 402 to every
        // write and the whole UI is read-only. A new tenant in production
        // gets a trial; the demo needs the same or it cannot be tested at
        // all. `trial_ends_at` is deliberately not mass-assignable — billing
        // state must never arrive in a request payload — hence forceFill.
        $tenant->forceFill([
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 30)),
        ])->save();

        $this->line("Provisioning [{$database}] — schema, roles, chart of accounts, fiscal year…");

        // The saga creates the owner; the remaining roles are added after so
        // each permission level has a face to log in as.
        $provision($tenant, [
            'name' => self::DEMO_USERS['owner'],
            'email' => 'owner@'.$subdomain.'.local',
            'password' => $password,
        ]);

        $this->addRemainingRoles($tenant, $subdomain, $password);

        $this->newLine();
        $this->info('Demo tenant ready.');
        $this->credentials($subdomain, $password);

        return self::SUCCESS;
    }

    /** Everything except `owner`, which ProvisionTenant already created. */
    private function addRemainingRoles(Tenant $tenant, string $subdomain, string $password): void
    {
        $tenant->makeCurrent();

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (self::DEMO_USERS as $role => $name) {
                if ($role === 'owner') {
                    continue;
                }

                User::create([
                    'name' => $name,
                    'email' => $role.'@'.$subdomain.'.local',
                    'password' => Hash::make($password),
                ])->assignRole($role);
            }
        } finally {
            Tenant::forgetCurrent();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function credentials(string $subdomain, string $password): void
    {
        $this->newLine();
        $this->line("  URL       http://{$subdomain}.local:8000   (php artisan serve)");
        $this->line('  Password  '.$password.'   (same for every login below)');
        $this->newLine();

        $this->table(
            ['Role', 'Email', 'Permissions GRANTED (spec 04)'],
            [
                ['owner', "owner@{$subdomain}.local", 'everything, incl. billing, period close, users'],
                ['accountant', "accountant@{$subdomain}.local", 'post, void, reverse, close periods, reports'],
                ['bookkeeper', "bookkeeper@{$subdomain}.local", 'documents.create + journal.draft — NOT post'],
                ['auditor', "auditor@{$subdomain}.local", 'view + reports.export only'],
            ]
        );

        // Say this plainly rather than let the table above be read as a
        // guarantee: the permissions are seeded, but no route or controller
        // consults them yet, so any logged-in role can currently reach any
        // write endpoint. Enforcement waits on the open policy question in
        // docs/specs/06 ("bookkeeper posting rights: post-but-not-close vs
        // draft-only").
        $this->newLine();
        $this->warn('  Known gap: these permissions are SEEDED but NOT ENFORCED at the routes yet.');
        $this->line('  Writes are gated on the subscription (can-post), not on the role.');
        $this->newLine();
        $this->line('  Walkthrough: docs/e2e-test-plan.md');
    }
}
