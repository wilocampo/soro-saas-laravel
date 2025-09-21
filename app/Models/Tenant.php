<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantCollection;

class Tenant extends Model implements IsTenant
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'subdomain',
        'database',
        'is_active',
        'settings',
    ];

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
        return $this->database ?? 'tenant_' . $this->id;
    }

    public function getSettings(string $key = null, $default = null)
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

    /**
     * Get the value of the tenant's primary key.
     */
    public function getKeyValue(): mixed
    {
        return $this->getKey();
    }

    /**
     * Create a new Eloquent Collection instance.
     */
    public function newCollection(array $models = []): TenantCollection
    {
        return new TenantCollection($models);
    }

    /**
     * Get the current tenant.
     */
    public static function current(): ?static
    {
        return app('currentTenant');
    }

    /**
     * Check if there is a current tenant.
     */
    public static function checkCurrent(): bool
    {
        return static::current() !== null;
    }

    /**
     * Forget the current tenant.
     */
    public static function forgetCurrent(): ?static
    {
        $current = static::current();
        app()->forgetInstance('currentTenant');
        return $current;
    }

    /**
     * Make this tenant the current one.
     */
    public function makeCurrent(): static
    {
        app()->instance('currentTenant', $this);
        return $this;
    }

    /**
     * Forget this tenant.
     */
    public function forget(): static
    {
        if ($this->isCurrent()) {
            static::forgetCurrent();
        }
        return $this;
    }

    /**
     * Check if this tenant is the current one.
     */
    public function isCurrent(): bool
    {
        return static::current()?->getKey() === $this->getKey();
    }

    /**
     * Execute a callable within this tenant's context.
     */
    public function execute(callable $callable): mixed
    {
        $original = static::current();
        
        $this->makeCurrent();
        
        try {
            return $callable();
        } finally {
            if ($original) {
                $original->makeCurrent();
            } else {
                static::forgetCurrent();
            }
        }
    }

    /**
     * Create a callback that will execute within this tenant's context.
     */
    public function callback(callable $callable): \Closure
    {
        return function (...$args) use ($callable) {
            return $this->execute(fn() => $callable(...$args));
        };
    }
}
