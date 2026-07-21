<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The tenant's BIR registration (docs/specs/01 §7, 03 §1). A singleton row:
 * registered name, address and TIN+branch appear on every invoice and on the
 * mandatory header of every generated book and report (RMC 5-2021 Annex B
 * item 4), and the ACCN is the go-live gate.
 */
class CompanyProfile extends Model
{
    /** Seeded when a tenant is provisioned; the operator must replace it. */
    public const PLACEHOLDER_TIN = '000000000';

    protected $table = 'company_profile';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'accn_issued_at' => 'date',
        'twa_effective_date' => 'date',
        'is_twa' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }

    /** TIN as BIR prints it: 000-000-000-00000. */
    public function formattedTin(): string
    {
        return implode('-', [
            substr($this->tin, 0, 3),
            substr($this->tin, 3, 3),
            substr($this->tin, 6, 3),
            str_pad($this->branch_code, 5, '0', STR_PAD_LEFT),
        ]);
    }

    /** Still carrying the provisioning placeholder — nothing may be issued. */
    public function isUnconfigured(): bool
    {
        return $this->tin === self::PLACEHOLDER_TIN;
    }

    /** No Acknowledgement Certificate yet — the system is not registered. */
    public function isAccredited(): bool
    {
        return $this->accn !== null && $this->accn !== '';
    }
}
