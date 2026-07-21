<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor bill. Post-EOPT the expanded withholding tax accrues at the
 * BOOKING date, not at payment (RR 4-2024) — so the accrual template books
 * Withholding Tax Payable here (docs/specs/02 §4.3).
 */
class VendorBill extends Model
{
    protected $table = 'vendor_bills';

    protected $guarded = ['id'];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
        'net_centavos' => 'integer',
        'input_vat_centavos' => 'integer',
        'ewt_centavos' => 'integer',
        'total_centavos' => 'integer',
        'amount_paid_centavos' => 'integer',
    ];

    /** @return HasMany<VendorBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorBillLine::class)->orderBy('line_no');
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

    /** Payable = net + input VAT − EWT withheld from the vendor. */
    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $this->net_centavos = (int) $lines->sum('net_centavos');
        $this->input_vat_centavos = (int) $lines->sum('input_vat_centavos');
        $this->total_centavos = $this->net_centavos + $this->input_vat_centavos - $this->ewt_centavos;
    }
}
