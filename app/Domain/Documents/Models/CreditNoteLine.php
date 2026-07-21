<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    protected $table = 'credit_note_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'net_centavos' => 'integer',
        'vat_centavos' => 'integer',
    ];

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
