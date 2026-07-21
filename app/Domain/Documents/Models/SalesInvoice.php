<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Invoice — post-EOPT the single PRINCIPAL VAT document for goods and
 * services alike (RR 7-2024). A subledger row that references the journal
 * entry it produced; it never carries a running balance (docs/specs/02 §0).
 */
class SalesInvoice extends Model
{
    protected $table = 'sales_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'net_centavos' => 'integer',
        'vat_centavos' => 'integer',
        'exempt_centavos' => 'integer',
        'zero_rated_centavos' => 'integer',
        'total_centavos' => 'integer',
        'amount_paid_centavos' => 'integer',
    ];

    /** @return HasMany<SalesInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function outstandingCentavos(): int
    {
        return $this->total_centavos - $this->amount_paid_centavos;
    }

    /** Recompute the money columns from the lines (integer centavos only). */
    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $this->net_centavos = (int) $lines->sum('net_centavos');
        $this->vat_centavos = (int) $lines->sum('vat_centavos');
        // Control total is defined as the SUM of the components, so the
        // document balances by construction (docs/specs/02 §3).
        // exempt/zero_rated are a BREAKDOWN of net for the VAT return
        // (spec 03 invoice fields) — never added on top of it.
        $this->total_centavos = $this->net_centavos + $this->vat_centavos;
    }
}
