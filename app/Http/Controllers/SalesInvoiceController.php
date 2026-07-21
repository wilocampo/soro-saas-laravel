<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\DocumentCanceller;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Exceptions\CannotCancel;
use App\Domain\Documents\Exceptions\InvoiceNotCompliant;
use App\Domain\Documents\InvoiceCompliance;
use App\Domain\Documents\InvoiceMailer;
use App\Domain\Documents\InvoiceRenderer;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\Exceptions\LedgerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales invoices — post-EOPT the single principal VAT document for goods and
 * services alike (RR 7-2024, docs/specs/03 §4).
 *
 * The controller never computes accounting: it validates input, hands the
 * document to `DocumentPoster`, and reports what came back. Serial numbers
 * are drawn by the poster inside the posting transaction, never here.
 */
class SalesInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $invoices = SalesInvoice::query()
            ->with('partner:id,registered_name,code')
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'status' => $request->string('status')->toString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Invoices/Create', [
            'customers' => Partner::query()->where('is_customer', true)->where('is_active', true)
                ->orderBy('registered_name')->get(['id', 'code', 'registered_name', 'is_vat_registered', 'payment_terms_days']),
            'revenueAccounts' => $this->revenueAccounts(),
            'taxCodes' => DB::table('tax_codes')->where('kind', 'output_vat')->get(['id', 'code', 'rate_bp']),
            'isVatRegistered' => (bool) DB::table('ledger_settings')->where('id', 1)->value('is_vat_registered'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            // Money arrives as integer centavos (spec 11 §5).
            'lines.*.net_centavos' => ['required', 'integer', 'min:1'],
            'lines.*.vat_centavos' => ['required', 'integer', 'min:0'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.tax_code_id' => ['nullable', 'exists:tax_codes,id'],
        ]);

        try {
            $invoice = DB::transaction(function () use ($data) {
                $invoice = SalesInvoice::create([
                    'partner_id' => $data['partner_id'],
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'status' => 'issued',
                ]);

                foreach (array_values($data['lines']) as $index => $line) {
                    SalesInvoiceLine::create($line + [
                        'sales_invoice_id' => $invoice->id,
                        'line_no' => $index + 1,
                    ]);
                }

                $invoice->load('lines');
                $invoice->recalculateTotals();
                $invoice->save();

                // Compliance gate BEFORE the serial is drawn: a rejected
                // invoice must not burn a number (CLAUDE.md #6).
                app(InvoiceCompliance::class)->assertIssuable($invoice->load('partner'));

                app(DocumentPoster::class)->post($invoice);

                return $invoice;
            });
        } catch (InvoiceNotCompliant|LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('invoices.show', $invoice->fresh())
            ->with('success', "Invoice {$invoice->fresh()->invoice_number} issued.");
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load(['lines', 'partner']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'outstanding' => app(DocumentBalances::class)->outstandingCentavos($invoice),
            'compliance' => app(InvoiceCompliance::class)->check($invoice),
            'journalEntry' => $invoice->journal_entry_id === null ? null : DB::table('journal_entries')
                ->where('id', $invoice->journal_entry_id)
                ->first(['id', 'entry_number', 'entry_date', 'status']),
            'lines' => $invoice->journal_entry_id === null ? [] : DB::table('journal_lines as jl')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('jl.journal_entry_id', $invoice->journal_entry_id)
                ->orderBy('jl.line_no')
                ->get(['a.code', 'a.name', 'jl.debit_centavos', 'jl.credit_centavos', 'jl.memo']),
        ]);
    }

    /** Streams the BIR-faithful PDF (spec 03 §4). */
    public function pdf(SalesInvoice $invoice): HttpResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'soro-invoice-').'.pdf';

        try {
            app(InvoiceRenderer::class)->pdf($invoice->load(['lines', 'partner']), $path);
            $pdf = (string) file_get_contents($path);
        } finally {
            // The document is re-rendered on demand; nothing is cached to disk.
            @unlink($path);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$invoice->invoice_number}.pdf\"",
        ]);
    }

    public function email(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $to = $request->validate(['to' => ['nullable', 'email']])['to'] ?? null;

        try {
            app(InvoiceMailer::class)->send($invoice->load('partner'), $to);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->invoice_number} sent.");
    }

    /**
     * Cancel while the period is open; otherwise the canceller refuses and
     * tells the operator to issue a credit note (RR 7-2024).
     */
    public function cancel(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        try {
            app(DocumentCanceller::class)->cancel($invoice, $reason);
        } catch (CannotCancel|LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->invoice_number} cancelled.");
    }

    /** @return Collection<int, \stdClass> */
    private function revenueAccounts(): Collection
    {
        return DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('t.code', 'income')
            ->where('a.is_postable', true)
            ->where('a.is_active', true)
            ->orderBy('a.code')
            ->get(['a.id', 'a.code', 'a.name']);
    }
}
