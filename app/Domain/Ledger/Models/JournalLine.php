<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Exceptions\ImmutableEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** READ model — see JournalEntry. Lines are inserted while draft, then frozen. */
class JournalLine extends Model
{
    protected $table = 'journal_lines';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'entry_date' => 'date',
        'debit_centavos' => 'integer',
        'credit_centavos' => 'integer',
        'tax_base_centavos' => 'integer',
    ];

    protected static function booted(): void
    {
        $deny = function (): never {
            throw new ImmutableEntry('journal_lines is written only by PostingService (CLAUDE.md rule 1).');
        };

        static::creating($deny);
        static::updating($deny);
        static::deleting($deny);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
