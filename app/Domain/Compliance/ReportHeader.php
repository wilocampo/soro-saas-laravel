<?php

namespace App\Domain\Compliance;

use App\Domain\Ledger\Models\CompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The header/footer block the BIR requires on EVERY generated book, financial
 * statement, report and document (RMC 5-2021 Annex B item 4): registered
 * name, address, VAT/NON-VAT TIN with branch code, software name + version,
 * the user who generated it, and the date-time.
 *
 * One component, used everywhere, so a report can never ship without it.
 */
class ReportHeader
{
    /** @return array<string, string|null> */
    public function for(string $title, ?CarbonImmutable $generatedAt = null): array
    {
        $profile = CompanyProfile::current();
        $vatRegistered = (bool) DB::table('ledger_settings')->where('id', 1)->value('is_vat_registered');
        $generatedAt ??= CarbonImmutable::now();   // app TZ is Asia/Manila (D18)

        return [
            'title' => $title,
            'registered_name' => $profile->registered_name,
            'registered_address' => $profile->registered_address,
            // BIR wants the registration status printed next to the TIN.
            'tin' => ($vatRegistered ? 'VAT REG. TIN ' : 'NON-VAT TIN ').$profile->formattedTin(),
            'is_vat_registered' => $vatRegistered ? '1' : '0',
            'accn' => $profile->accn,
            'software' => config('compliance.software_name').' v'.config('compliance.software_version'),
            'generated_by' => $this->generatedBy(),
            'generated_at' => $generatedAt->format('Y-m-d H:i:s T'),
        ];
    }

    private function generatedBy(): string
    {
        $user = auth()->user();

        return $user?->getAttribute('name') ?? 'system';
    }
}
