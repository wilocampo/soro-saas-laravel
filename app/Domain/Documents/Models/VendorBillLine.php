<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillLine extends Model
{
    protected $table = 'vendor_bill_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'net_centavos' => 'integer',
        'input_vat_centavos' => 'integer',
    ];

    /** @return BelongsTo<VendorBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}
