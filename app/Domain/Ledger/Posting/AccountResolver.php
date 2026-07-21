<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;

/**
 * Role → account id, from the `account_roles` map (docs/specs/01 §7).
 * Posting rules never hard-code account codes; a missing role is a
 * configuration error surfaced loudly, never a silent fallback.
 */
class AccountResolver
{
    /** @var array<string, int>|null */
    private ?array $cache = null;

    public function id(string $role): int
    {
        $this->cache ??= DB::table('account_roles')
            ->pluck('account_id', 'role')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! isset($this->cache[$role])) {
            throw new InvalidDraft("No account is mapped to the role [{$role}] — configure account_roles (01 §7).");
        }

        return $this->cache[$role];
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
