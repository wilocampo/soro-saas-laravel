<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A credit or debit note (docs/specs/03 §4). The lawful way to adjust an
 * invoice that can no longer be cancelled — it is a document in its own
 * right, with its own serial, never an edit of the original.
 */
class CreditNote extends Model
{
    protected $table = 'credit_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'note_date' => 'date',
        'issued_at' => 'datetime',
        'net_centavos' => 'integer',
        'vat_centavos' => 'integer',
        'total_centavos' => 'integer',
    ];

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** A credit note reduces what is owed; a debit note increases it. */
    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $this->net_centavos = (int) $lines->sum('net_centavos');
        $this->vat_centavos = (int) $lines->sum('vat_centavos');
        $this->total_centavos = $this->net_centavos + $this->vat_centavos;
    }
}
