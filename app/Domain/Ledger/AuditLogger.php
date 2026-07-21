<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;

/**
 * Hash-chained, append-only audit writer (docs/specs/01 §6).
 * row_hash = sha256(prev_hash || canonical(payload)); ledger:verify walks
 * the chain. Every ledger state change calls this in the SAME transaction.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        string $event,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?string $documentNumber = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?int $actorId = null,
        ?string $actorName = null,
    ): void {
        // BIR requires a user against every logged action (RMC 5-2021 Annex B
        // item 8), so an unnamed caller resolves to the current user or the
        // seeded system actor rather than writing a null.
        $actorId ??= $this->resolveActorId();
        $actorName ??= $this->actorName($actorId);

        // Serialize chain writers: the last row's hash is this row's prev.
        $prev = DB::table('audit_log')->orderByDesc('id')->lockForUpdate()->value('row_hash');

        $occurredAt = now()->format('Y-m-d H:i:s.u');

        $payload = [
            'occurred_at' => $occurredAt,
            'actor_user_id' => $actorId,
            'actor_name' => $actorName,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'document_number' => $documentNumber,
            'before' => $before,
            'after' => $after,
            'context' => $context,
        ];

        DB::table('audit_log')->insert([
            'occurred_at' => $occurredAt,
            'actor_user_id' => $actorId,
            'actor_name' => $actorName,
            'actor_ip' => request()->ip() ? inet_pton((string) request()->ip()) ?: null : null,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'document_number' => $documentNumber,
            'before_json' => $before === null ? null : json_encode($before),
            'after_json' => $after === null ? null : json_encode($after),
            'context_json' => $context === null ? null : json_encode($context),
            'prev_hash' => $prev,
            'row_hash' => self::hash($prev, $payload),
        ]);
    }

    private function resolveActorId(): int
    {
        $id = auth()->id();
        if ($id !== null) {
            return (int) $id;
        }

        $system = DB::table('users')->where('is_system', true)->value('id');
        if ($system === null) {
            throw new InvalidDraft('No authenticated user and no system actor is seeded.');
        }

        return (int) $system;
    }

    private function actorName(int $actorId): string
    {
        return (string) (DB::table('users')->where('id', $actorId)->value('name') ?? 'system');
    }

    /**
     * Deterministic canonical form — sorted keys, no whitespace.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function canonical(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$v) {
                    $sort($v);
                }
            }
        };
        $sort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<string, mixed>  $payload */
    public static function hash(?string $prevHash, array $payload): string
    {
        return hash('sha256', ($prevHash ?? '').self::canonical($payload));
    }
}
