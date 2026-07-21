<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    protected $table = 'sales_invoice_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'net_centavos' => 'integer',
        'vat_centavos' => 'integer',
    ];

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
