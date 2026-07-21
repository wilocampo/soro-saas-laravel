<?php

namespace App\Domain\Documents\Import;

use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Models\VendorBillLine;
use Illuminate\Support\Facades\DB;

/**
 * Open A/R and A/P documents carried in at cutover (docs/specs/02 §4.2).
 *
 * ⚠ These documents are NOT posted, and that is the whole point. Their money
 * is already inside the A/R and A/P figures that `OpeningBalanceService`
 * posts as one opening entry; posting each document as well would double the
 * receivable. They exist so aging, statements and payment application have
 * something to work against — the subledger detail behind an opening
 * balance. `is_opening` records why the journal_entry_id is null.
 *
 * The operator's obligation, and what `verifyAgainstOpeningBalance()`
 * checks: the imported documents must sum to the opening A/R (or A/P) to
 * the centavo.
 *
 * Header: type,number,partner_code,document_date,due_date,description,
 *         net_centavos,vat_centavos,amount_paid_centavos
 * (`type` is sales_invoice or vendor_bill.)
 */
class OpenDocumentImporter
{
    public function __construct(private readonly CsvReader $reader) {}

    public function import(string $path): ImportResult
    {
        $result = new ImportResult;

        $required = ['type', 'number', 'partner_code', 'document_date', 'net_centavos'];

        foreach ($this->reader->rows($path, $required) as $number => $row) {
            try {
                DB::transaction(fn () => $this->createDocument($row));
                $result->created++;
            } catch (\InvalidArgumentException $e) {
                $result->fail($number, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Imported open documents must tie to the opening balance posted for the
     * control account. A mismatch means the cutover is wrong, and finding
     * that on day one is far cheaper than finding it in an audit.
     *
     * @return array{documents:int, control:int, difference:int}
     */
    public function verifyAgainstOpeningBalance(string $type, string $controlAccountCode): array
    {
        $table = $type === 'sales_invoice' ? 'sales_invoices' : 'vendor_bills';

        $documents = (int) DB::table($table)
            ->where('is_opening', true)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(total_centavos - amount_paid_centavos), 0) AS outstanding')
            ->value('outstanding');

        $accountId = DB::table('accounts')->where('code', $controlAccountCode)->value('id');

        $control = (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $accountId)
            ->where('je.journal_book', 'opening_balance')
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) - COALESCE(SUM(jl.credit_centavos),0) AS net')
            ->value('net');

        // A/P is a credit balance; compare magnitudes.
        $control = abs($control);

        return [
            'documents' => $documents,
            'control' => $control,
            'difference' => $documents - $control,
        ];
    }

    /** @param  array<string, string>  $row */
    private function createDocument(array $row): void
    {
        $type = strtolower($row['type']);

        if (! in_array($type, ['sales_invoice', 'vendor_bill'], true)) {
            throw new \InvalidArgumentException("type [{$row['type']}] must be sales_invoice or vendor_bill.");
        }

        $partner = Partner::query()->where('code', $row['partner_code'])->first();
        if ($partner === null) {
            throw new \InvalidArgumentException("partner_code [{$row['partner_code']}] does not exist — import partners first.");
        }
        if ($row['number'] === '') {
            throw new \InvalidArgumentException('number is blank — a carried-in document keeps its ORIGINAL number from the prior system.');
        }

        $net = (int) $row['net_centavos'];
        $vat = (int) ($row['vat_centavos'] ?? 0);
        $paid = (int) ($row['amount_paid_centavos'] ?? 0);
        $total = $net + $vat;

        if ($net <= 0) {
            throw new \InvalidArgumentException('net_centavos must be positive.');
        }
        if ($paid > $total) {
            throw new \InvalidArgumentException("amount_paid_centavos ({$paid}) exceeds the document total ({$total}).");
        }
        if ($paid === $total) {
            throw new \InvalidArgumentException('the document is fully settled — only OPEN documents are carried in.');
        }

        $description = ($row['description'] ?? '') ?: 'Opening balance detail';
        $account = $this->defaultAccountId($type);

        if ($type === 'sales_invoice') {
            $invoice = SalesInvoice::create([
                // The prior system's serial: continuing the series is a BIR
                // requirement, so the number is preserved verbatim.
                'invoice_number' => $row['number'],
                'partner_id' => $partner->id,
                'invoice_date' => $row['document_date'],
                'due_date' => ($row['due_date'] ?? '') ?: null,
                'status' => 'issued',
                'is_opening' => true,
                'net_centavos' => $net,
                'vat_centavos' => $vat,
                'total_centavos' => $total,
                'amount_paid_centavos' => $paid,
                'opening_paid_centavos' => $paid,
            ]);

            SalesInvoiceLine::create([
                'sales_invoice_id' => $invoice->id,
                'line_no' => 1,
                'description' => $description,
                'net_centavos' => $net,
                'vat_centavos' => $vat,
                'account_id' => $account,
            ]);

            return;
        }

        $bill = VendorBill::create([
            'bill_number' => $row['number'],
            'reference' => $row['number'],
            'partner_id' => $partner->id,
            'bill_date' => $row['document_date'],
            'due_date' => ($row['due_date'] ?? '') ?: null,
            'status' => 'open',
            'is_opening' => true,
            'net_centavos' => $net,
            'input_vat_centavos' => $vat,
            'total_centavos' => $total,
            'amount_paid_centavos' => $paid,
        ]);

        VendorBillLine::create([
            'vendor_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => $description,
            'net_centavos' => $net,
            'input_vat_centavos' => $vat,
            'account_id' => $account,
        ]);
    }

    /**
     * The line needs an account for shape's sake even though nothing posts.
     * Revenue/expense is deliberate: if the operator later cancels a carried
     * document the reversal lands somewhere sane.
     */
    private function defaultAccountId(string $type): int
    {
        $id = $type === 'sales_invoice'
            ? DB::table('account_roles')->where('role', 'sales')->value('account_id')
            : DB::table('accounts as a')
                ->join('account_types as t', 't.id', '=', 'a.account_type_id')
                ->where('t.code', 'expense')
                ->where('a.is_postable', true)
                ->where('a.is_system', false)
                ->orderBy('a.code')
                ->value('a.id');

        if ($id === null) {
            throw new \InvalidArgumentException('No postable revenue/expense account exists to attach the imported line to.');
        }

        return (int) $id;
    }
}
