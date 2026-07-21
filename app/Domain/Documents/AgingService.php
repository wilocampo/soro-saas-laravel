<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A/R and A/P aging, read from the SUBLEDGER (docs/specs/02 §4.2).
 *
 * Deliberately not derived from the GL: under cash basis the books hold no
 * A/R or A/P control account at all, yet the business still needs to know
 * who owes what. Under accrual the two must agree to the centavo, which is
 * exactly what `assertTiesToControlAccount()` checks.
 */
class AgingService
{
    /** Upper bound in days past due; null = the open-ended oldest bucket. */
    public const BUCKETS = [
        'current' => 0,     // not yet due
        '1_30' => 30,
        '31_60' => 60,
        '61_90' => 90,
        'over_90' => null,
    ];

    /** @return array{as_of:string, partners:list<array<string,mixed>>, totals:array<string,int>} */
    public function receivables(?CarbonImmutable $asOf = null): array
    {
        return $this->age('sales_invoice', 'sales_invoices', 'invoice_date', $asOf);
    }

    /** @return array{as_of:string, partners:list<array<string,mixed>>, totals:array<string,int>} */
    public function payables(?CarbonImmutable $asOf = null): array
    {
        return $this->age('vendor_bill', 'vendor_bills', 'bill_date', $asOf);
    }

    /**
     * @return array{as_of:string, partners:list<array<string,mixed>>, totals:array<string,int>}
     */
    private function age(string $type, string $table, string $dateColumn, ?CarbonImmutable $asOf): array
    {
        $asOf ??= CarbonImmutable::now();
        $balances = app(DocumentBalances::class);

        $documents = DB::table("{$table} as d")
            ->join('partners as p', 'p.id', '=', 'd.partner_id')
            ->where('d.status', '!=', 'cancelled')
            ->whereDate("d.{$dateColumn}", '<=', $asOf->toDateString())
            ->orderBy('p.registered_name')
            ->get([
                'd.id', 'd.partner_id', "d.{$dateColumn} as document_date", 'd.due_date',
                'd.total_centavos', 'p.registered_name',
            ]);

        $byPartner = [];
        $totals = array_fill_keys([...array_keys(self::BUCKETS), 'total'], 0);

        foreach ($documents as $document) {
            $outstanding = $this->outstanding($balances, $type, (int) $document->id, (int) $document->total_centavos);

            if ($outstanding === 0) {
                continue;
            }

            $bucket = $this->bucketFor($document->due_date ?? $document->document_date, $asOf);

            $partner = $byPartner[$document->partner_id] ??= [
                'partner_id' => (int) $document->partner_id,
                'registered_name' => $document->registered_name,
                ...array_fill_keys([...array_keys(self::BUCKETS), 'total'], 0),
            ];

            $partner[$bucket] += $outstanding;
            $partner['total'] += $outstanding;
            $byPartner[$document->partner_id] = $partner;

            $totals[$bucket] += $outstanding;
            $totals['total'] += $outstanding;
        }

        return [
            'as_of' => $asOf->toDateString(),
            'partners' => array_values($byPartner),
            'totals' => $totals,
        ];
    }

    private function outstanding(DocumentBalances $balances, string $type, int $id, int $total): int
    {
        $model = $type === 'sales_invoice'
            ? SalesInvoice::find($id)
            : VendorBill::find($id);

        return $model === null ? $total : max(0, $balances->outstandingCentavos($model));
    }

    private function bucketFor(string $dueDate, CarbonImmutable $asOf): string
    {
        $daysPastDue = CarbonImmutable::parse($dueDate)->startOfDay()->diffInDays($asOf->startOfDay(), false);

        if ($daysPastDue <= 0) {
            return 'current';
        }

        foreach (self::BUCKETS as $bucket => $upperBound) {
            if ($bucket === 'current') {
                continue;
            }
            if ($upperBound === null || $daysPastDue <= $upperBound) {
                return $bucket;
            }
        }

        return 'over_90';
    }
}
