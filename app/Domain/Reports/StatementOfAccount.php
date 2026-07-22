<?php

namespace App\Domain\Reports;

use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer Statement of Account (Phase 3).
 *
 * ⚠ An SOA is a **supplementary** document, never a principal one: it is
 * proof of a running balance, not of a sale, and it is NOT valid to support
 * the recipient's input VAT (RR 18-2012 / RMO 12-2013, surviving EOPT — see
 * docs/specs/03 §4). The template says so on its face, because a customer
 * who files this instead of the invoice loses the claim.
 *
 * Built from the SUBLEDGER, like the aging report: a cash-basis registrant
 * has no A/R control account and still owes its customers a statement.
 */
class StatementOfAccount
{
    public function __construct(private readonly DocumentBalances $balances) {}

    /**
     * @return array{
     *   partner:array<string,mixed>, from:string, to:string,
     *   opening_centavos:int, closing_centavos:int,
     *   rows:list<array<string,mixed>>, aging:array<string,int>
     * }
     */
    public function forCustomer(int $partnerId, string $from, string $to): array
    {
        $partner = Partner::query()->findOrFail($partnerId);

        $opening = $this->outstandingBefore($partnerId, $from);
        $running = $opening;
        $rows = [];

        foreach ($this->movements($partnerId, $from, $to) as $movement) {
            $running += $movement['charge'] - $movement['payment'];
            $rows[] = $movement + ['balance' => $running];
        }

        return [
            'partner' => $partner->only([
                'id', 'code', 'registered_name', 'address', 'tin', 'branch_code', 'email', 'payment_terms_days',
            ]),
            'from' => $from,
            'to' => $to,
            'opening_centavos' => $opening,
            'closing_centavos' => $running,
            'rows' => $rows,
            'aging' => $this->agingBuckets($partnerId, CarbonImmutable::parse($to)),
        ];
    }

    /**
     * Charges (invoices, debit notes) and credits (payments, credit notes)
     * in date order — what the customer needs to reconcile against its own
     * ledger.
     *
     * @return list<array<string, mixed>>
     */
    private function movements(int $partnerId, string $from, string $to): array
    {
        $rows = [];

        foreach ($this->invoices($partnerId, $from, $to) as $invoice) {
            $rows[] = [
                'date' => (string) $invoice->invoice_date,
                'reference' => $invoice->invoice_number,
                'particulars' => $invoice->status === 'cancelled'
                    ? 'Invoice (cancelled)'
                    : 'Invoice',
                // A cancelled invoice is shown at zero, not hidden: the
                // customer may hold a copy and needs to see it was voided.
                'charge' => $invoice->status === 'cancelled' ? 0 : (int) $invoice->total_centavos,
                'payment' => 0,
            ];
        }

        foreach ($this->notes($partnerId, $from, $to) as $note) {
            $isCredit = $note->type === 'credit';
            $rows[] = [
                'date' => (string) $note->note_date,
                'reference' => $note->note_number,
                'particulars' => ($isCredit ? 'Credit note' : 'Debit note').' — '.$note->reason,
                'charge' => $isCredit ? 0 : (int) $note->total_centavos,
                'payment' => $isCredit ? (int) $note->total_centavos : 0,
            ];
        }

        foreach ($this->receipts($partnerId, $from, $to) as $payment) {
            $rows[] = [
                'date' => (string) $payment->payment_date,
                'reference' => $payment->payment_number,
                // Cash plus tax withheld is what actually settled the debt.
                'particulars' => 'Payment received'
                    .($payment->ewt_centavos > 0 ? " (incl. {$payment->atc_code} withheld)" : ''),
                'charge' => 0,
                'payment' => (int) $payment->amount_centavos + (int) $payment->ewt_centavos,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['reference'] ?? ''] <=> [$b['date'], $b['reference'] ?? '']);

        return $rows;
    }

    private function outstandingBefore(int $partnerId, string $from): int
    {
        $charges = (int) DB::table('sales_invoices')
            ->where('partner_id', $partnerId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '<', $from)
            ->sum('total_centavos');

        $notes = DB::table('credit_notes')
            ->where('partner_id', $partnerId)
            ->where('side', 'customer')
            ->where('status', 'issued')
            ->whereDate('note_date', '<', $from)
            ->get(['type', 'total_centavos']);

        foreach ($notes as $note) {
            $charges += $note->type === 'credit' ? -(int) $note->total_centavos : (int) $note->total_centavos;
        }

        $settled = DB::table('payments')
            ->where('partner_id', $partnerId)
            ->where('direction', 'received')
            ->where('status', '!=', 'cancelled')
            ->whereDate('payment_date', '<', $from)
            ->selectRaw('COALESCE(SUM(amount_centavos),0) + COALESCE(SUM(ewt_centavos),0) AS settled')
            ->value('settled');

        return $charges - (int) $settled;
    }

    /** @return array<string, int> */
    private function agingBuckets(int $partnerId, CarbonImmutable $asOf): array
    {
        $buckets = array_fill_keys(['current', '1_30', '31_60', '61_90', 'over_90'], 0);

        $invoices = SalesInvoice::query()
            ->where('partner_id', $partnerId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '<=', $asOf->toDateString())
            ->get();

        foreach ($invoices as $invoice) {
            $outstanding = $this->balances->outstandingCentavos($invoice);

            if ($outstanding <= 0) {
                continue;
            }

            $due = CarbonImmutable::parse(($invoice->due_date ?? $invoice->invoice_date)->toDateString());
            $daysPastDue = $due->startOfDay()->diffInDays($asOf->startOfDay(), false);

            $bucket = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => '1_30',
                $daysPastDue <= 60 => '31_60',
                $daysPastDue <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $outstanding;
        }

        return $buckets;
    }

    /** @return Collection<int, \stdClass> */
    private function invoices(int $partnerId, string $from, string $to)
    {
        return DB::table('sales_invoices')
            ->where('partner_id', $partnerId)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->orderBy('invoice_date')
            ->get(['invoice_number', 'invoice_date', 'total_centavos', 'status']);
    }

    /** @return Collection<int, \stdClass> */
    private function notes(int $partnerId, string $from, string $to)
    {
        return DB::table('credit_notes')
            ->where('partner_id', $partnerId)
            ->where('side', 'customer')
            ->where('status', 'issued')
            ->whereDate('note_date', '>=', $from)
            ->whereDate('note_date', '<=', $to)
            ->orderBy('note_date')
            ->get(['note_number', 'note_date', 'type', 'total_centavos', 'reason']);
    }

    /** @return Collection<int, \stdClass> */
    private function receipts(int $partnerId, string $from, string $to)
    {
        return DB::table('payments')
            ->where('partner_id', $partnerId)
            ->where('direction', 'received')
            ->where('status', '!=', 'cancelled')
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->orderBy('payment_date')
            ->get(['payment_number', 'payment_date', 'amount_centavos', 'ewt_centavos', 'atc_code']);
    }
}
