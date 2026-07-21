<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Exceptions\ImmutableEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * READ model. The journal is written ONLY by PostingService via the query
 * builder (docs/specs/02 §0) — Eloquent writes are structurally disabled so
 * no controller/service can slip a mutation past the choke point.
 */
class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $guarded = ['*'];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'total_debit_centavos' => 'integer',
        'total_credit_centavos' => 'integer',
    ];

    protected static function booted(): void
    {
        $deny = function (): never {
            throw new ImmutableEntry('journal_entries is written only by PostingService (CLAUDE.md rule 1).');
        };

        static::creating($deny);
        static::updating($deny);
        static::deleting($deny);
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    /** @return BelongsTo<self, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
