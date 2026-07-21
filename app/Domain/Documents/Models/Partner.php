<?php

namespace App\Domain\Documents\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer and/or vendor. BIR counterparty identity (TIN + branch code,
 * VAT registration, sworn-declaration validity) is captured from day one
 * so the Phase-4 returns and alphalists have their data (docs/specs/03).
 */
class Partner extends Model
{
    protected $table = 'partners';

    protected $guarded = ['id'];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_vendor' => 'boolean',
        'is_vat_registered' => 'boolean',
        'is_active' => 'boolean',
        'sworn_declaration_valid_until' => 'date',
    ];

    /**
     * An absent or expired sworn declaration means the HIGHER withholding
     * rate applies (docs/specs/01 §7) — never silently assume the lower.
     */
    public function hasValidSwornDeclaration(?CarbonInterface $onDate = null): bool
    {
        $onDate ??= now();

        return $this->sworn_declaration_valid_until !== null
            && $this->sworn_declaration_valid_until->greaterThanOrEqualTo($onDate);
    }

    /** "123-456-789-00000" for display/print (spec 03 invoice fields). */
    public function formattedTin(): ?string
    {
        if ($this->tin === null) {
            return null;
        }

        return implode('-', str_split($this->tin, 3)).'-'.$this->branch_code;
    }
}
