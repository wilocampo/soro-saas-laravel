<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Date-effective tax lookups (docs/specs/01 §7): rates are DATA, never
 * hard-coded — a rate change is a new effective-dated row, and historical
 * documents keep resolving the rate that applied on their own date.
 */
class TaxResolver
{
    /** @return object{id:int, code:string, kind:string, rate_bp:int, account_id:?int, default_atc:?string} */
    public function taxCode(int $taxCodeId, CarbonImmutable $onDate): object
    {
        $row = DB::table('tax_codes')->where('id', $taxCodeId)->first();

        if ($row === null) {
            throw new InvalidDraft("Unknown tax code [{$taxCodeId}].");
        }

        $date = $onDate->toDateString();
        if ($row->effective_from > $date || ($row->effective_to !== null && $row->effective_to < $date)) {
            throw new InvalidDraft("Tax code [{$row->code}] is not effective on {$date}.");
        }

        return (object) [
            'id' => (int) $row->id,
            'code' => $row->code,
            'kind' => $row->kind,
            'rate_bp' => (int) $row->rate_bp,
            'account_id' => $row->account_id === null ? null : (int) $row->account_id,
            'default_atc' => $row->default_atc,
        ];
    }

    /**
     * Expanded-withholding rate for an ATC on a date. A payee whose sworn
     * declaration is absent/expired resolves the HIGHER rate (01 §7) —
     * that judgement belongs to the caller, which passes the payee type.
     */
    public function atcRateBp(string $atcCode, string $payeeType, CarbonImmutable $onDate): int
    {
        $date = $onDate->toDateString();

        $rate = DB::table('atc_rates')
            ->where('atc_code', $atcCode)
            ->where('payee_type', $payeeType)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->value('rate_bp');

        if ($rate === null) {
            throw new InvalidDraft("No effective ATC rate for [{$atcCode}]/[{$payeeType}] on {$date}.");
        }

        return (int) $rate;
    }
}
