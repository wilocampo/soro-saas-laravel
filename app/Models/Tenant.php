<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

/**
 * Tenancy context methods (makeCurrent/forget/execute/…) come from spatie's
 * ImplementsTenant trait — makeCurrent() MUST route through
 * MakeTenantCurrentAction so the switch_tenant_tasks (database swap) run.
 * A hand-rolled makeCurrent() that only binds the container skips the DB
 * switch and sends tenant-aware queue jobs to the landlord database.
 */
class Tenant extends Model implements IsTenant
{
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
    ];

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
