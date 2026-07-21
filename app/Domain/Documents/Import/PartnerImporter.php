<?php

namespace App\Domain\Documents\Import;

use App\Domain\Documents\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Customers and vendors from CSV (docs/specs/02, 03 §4).
 *
 * Header: code,registered_name,is_customer,is_vendor,tin,branch_code,
 *         address,is_vat_registered,taxpayer_type,default_atc_code,
 *         payment_terms_days,sworn_declaration_valid_until
 *
 * Upserts by `code`, so re-running a corrected file fixes rows instead of
 * duplicating them. The TIN is validated to nine digits because a malformed
 * TIN on an invoice is an input-tax-fatal defect for the buyer (spec 03 §4).
 */
class PartnerImporter
{
    public function __construct(private readonly CsvReader $reader) {}

    public function import(string $path): ImportResult
    {
        $result = new ImportResult;

        foreach ($this->reader->rows($path, ['code', 'registered_name']) as $number => $row) {
            try {
                $attributes = $this->attributes($row);
            } catch (\InvalidArgumentException $e) {
                $result->fail($number, $e->getMessage());

                continue;
            }

            DB::transaction(function () use ($row, $attributes, $result) {
                $existing = Partner::query()->where('code', $row['code'])->first();

                if ($existing === null) {
                    Partner::create(['code' => $row['code']] + $attributes);
                    $result->created++;
                } else {
                    $existing->update($attributes);
                    $result->updated++;
                }
            });
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row): array
    {
        if ($row['code'] === '') {
            throw new \InvalidArgumentException('code is blank.');
        }
        if ($row['registered_name'] === '') {
            throw new \InvalidArgumentException('registered_name is blank — BIR requires the counterparty\'s registered name.');
        }

        $tin = preg_replace('/\D/', '', $row['tin'] ?? '');
        if ($tin !== '' && strlen($tin) !== 9) {
            throw new \InvalidArgumentException("tin [{$row['tin']}] is not nine digits — the branch code goes in its own column.");
        }

        $isCustomer = $this->bool($row['is_customer'] ?? '');
        $isVendor = $this->bool($row['is_vendor'] ?? '');

        if (! $isCustomer && ! $isVendor) {
            throw new \InvalidArgumentException('the row is neither a customer nor a vendor.');
        }

        $taxpayerType = strtolower($row['taxpayer_type'] ?? '') ?: 'juridical';
        if (! in_array($taxpayerType, ['individual', 'juridical'], true)) {
            throw new \InvalidArgumentException("taxpayer_type [{$taxpayerType}] must be individual or juridical.");
        }

        return [
            'registered_name' => $row['registered_name'],
            'trade_name' => ($row['trade_name'] ?? '') ?: null,
            'is_customer' => $isCustomer,
            'is_vendor' => $isVendor,
            'tin' => $tin ?: null,
            'branch_code' => ($row['branch_code'] ?? '') ?: '000',
            'address' => ($row['address'] ?? '') ?: null,
            'is_vat_registered' => $this->bool($row['is_vat_registered'] ?? ''),
            'taxpayer_type' => $taxpayerType,
            'default_atc_code' => ($row['default_atc_code'] ?? '') ?: null,
            'payment_terms_days' => (int) ($row['payment_terms_days'] ?? 0),
            // Absent or expired, the HIGHER withholding rate applies (01 §7).
            'sworn_declaration_valid_until' => ($row['sworn_declaration_valid_until'] ?? '') ?: null,
            'is_active' => true,
        ];
    }

    private function bool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'y', 'yes', 'true', 't'], true);
    }
}
