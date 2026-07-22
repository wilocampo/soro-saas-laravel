<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

/**
 * Tenancy context methods (makeCurrent/forget/execute/…) come from spatie's
 * ImplementsTenant trait — makeCurrent() MUST route through
 * MakeTenantCurrentAction so the switch_tenant_tasks (database swap) run.
 * A hand-rolled makeCurrent() that only binds the container skips the DB
 * switch and sends tenant-aware queue jobs to the landlord database.
 *
 * The tenant is also the BILLABLE entity (Phase 5): the subscription
 * belongs to the business, not to whichever person signed up, and it lives
 * on the landlord connection so no tenant database holds card metadata.
 */
class Tenant extends Model implements IsTenant
{
    use Billable;
    use HasFactory;
    use ImplementsTenant;
    use UsesMultitenancyConfig;

    protected $fillable = [
        'name',
        'domain',
        'subdomain',
        'database',
        'is_active',
        'settings',
    ];

    // provisioning_status is intentionally NOT fillable — only the
    // ProvisionTenant saga transitions it (via forceFill).

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Whether this tenant may POST new work.
     *
     * A lapsed subscription never hides a taxpayer's own books — BIR holds
     * the REGISTRANT responsible for keeping and producing them, so locking
     * them out would put them in breach through no act of their own. It
     * degrades to read-only instead: everything stays visible and
     * exportable, nothing new can be posted (config/billing.php).
     */
    public function canPost(): bool
    {
        if (! config('billing.enabled')) {
            return true;   // SINGLE_TENANT installs bill by contract
        }

        if ($this->subscribed('default') || $this->onTrial()) {
            return true;
        }

        $endedAt = $this->subscription('default')?->ends_at;

        if ($endedAt === null) {
            return false;   // never subscribed and no trial left
        }

        // A card that fails at 2am must not stop Monday's invoicing.
        return CarbonImmutable::parse($endedAt)
            ->addDays((int) config('billing.grace_days'))
            ->isFuture();
    }

    /** Human-readable billing state for the UI and the gate's message. */
    public function billingStatus(): string
    {
        return match (true) {
            ! config('billing.enabled') => 'not_billed',
            $this->onTrial() => 'trialing',
            $this->subscribed('default') => 'active',
            $this->canPost() => 'grace',
            default => 'lapsed',
        };
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }

    public static function findBySubdomain(string $subdomain): ?self
    {
        return static::where('subdomain', $subdomain)
            ->where('is_active', true)
            ->first();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getDatabaseName(): string
    {
        return $this->database ?? 'tenant_'.$this->id;
    }

    public function getSettings(?string $key = null, $default = null)
    {
        $settings = $this->settings ?? [];

        if ($key === null) {
            return $settings;
        }

        return data_get($settings, $key, $default);
    }

    public function setSettings(array $settings): void
    {
        $this->update(['settings' => array_merge($this->settings ?? [], $settings)]);
    }
}
