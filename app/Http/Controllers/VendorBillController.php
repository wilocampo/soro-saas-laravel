<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Models\VendorBillLine;
use App\Domain\Ledger\Exceptions\LedgerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor bills (docs/specs/02 §4.3). The expanded withholding tax accrues at
 * the BOOKING date, not at payment (RR 4-2024) — so the EWT entered here is
 * what the posting rule books as a liability.
 */
class VendorBillController extends Controller
{
    public function index(Request $request): Response
    {
        $bills = VendorBill::query()
            ->with('partner:id,registered_name,code')
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Bills/Index', [
            'bills' => $bills,
            'status' => $request->string('status')->toString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Bills/Create', [
            'vendors' => Partner::query()->where('is_vendor', true)->where('is_active', true)
                ->orderBy('registered_name')
                ->get(['id', 'code', 'registered_name', 'is_vat_registered', 'default_atc_code', 'sworn_declaration_valid_until']),
            'expenseAccounts' => DB::table('accounts as a')
                ->join('account_types as t', 't.id', '=', 'a.account_type_id')
                ->whereIn('t.code', ['expense', 'asset'])
                ->where('a.is_postable', true)
                ->where('a.is_active', true)
                ->where('a.is_system', false)
                ->orderBy('a.code')
                ->get(['a.id', 'a.code', 'a.name']),
            'taxCodes' => DB::table('tax_codes')->where('kind', 'input_vat')->get(['id', 'code', 'rate_bp']),
            'atcRates' => DB::table('atc_rates')->orderBy('atc_code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            // The VENDOR's own number; ours is drawn by the poster.
            'bill_number' => ['required', 'string', 'max:40'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'ewt_centavos' => ['nullable', 'integer', 'min:0'],
            'atc_code' => ['nullable', 'required_with:ewt_centavos', 'string', 'max:16'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.net_centavos' => ['required', 'integer', 'min:1'],
            'lines.*.input_vat_centavos' => ['required', 'integer', 'min:0'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.tax_code_id' => ['nullable', 'exists:tax_codes,id'],
        ]);

        try {
            $bill = DB::transaction(function () use ($data) {
                $bill = VendorBill::create([
                    'partner_id' => $data['partner_id'],
                    'bill_number' => $data['bill_number'],
                    'bill_date' => $data['bill_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'ewt_centavos' => $data['ewt_centavos'] ?? 0,
                    'atc_code' => $data['atc_code'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'status' => 'open',
                ]);

                foreach (array_values($data['lines']) as $index => $line) {
                    VendorBillLine::create($line + [
                        'vendor_bill_id' => $bill->id,
                        'line_no' => $index + 1,
                    ]);
                }

                $bill->load('lines');
                $bill->recalculateTotals();
                $bill->save();

                app(DocumentPoster::class)->post($bill);

                return $bill;
            });
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bills.show', $bill->fresh())
            ->with('success', "Bill {$bill->fresh()->reference} recorded.");
    }

    public function show(VendorBill $bill): Response
    {
        $bill->load(['lines', 'partner']);

        return Inertia::render('Bills/Show', [
            'bill' => $bill,
            'outstanding' => app(DocumentBalances::class)->outstandingCentavos($bill),
            'receipts' => $bill->getMedia(VendorBill::RECEIPTS)->map(fn ($media) => [
                'id' => $media->id,
                'name' => $media->file_name,
                'size' => $media->size,
                'url' => $media->getUrl(),
            ]),
            'journalEntry' => $bill->journal_entry_id === null ? null : DB::table('journal_entries')
                ->where('id', $bill->journal_entry_id)
                ->first(['id', 'entry_number', 'entry_date', 'status']),
            'lines' => $bill->journal_entry_id === null ? [] : DB::table('journal_lines as jl')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('jl.journal_entry_id', $bill->journal_entry_id)
                ->orderBy('jl.line_no')
                ->get(['a.code', 'a.name', 'jl.debit_centavos', 'jl.credit_centavos', 'jl.memo']),
        ]);
    }

    /**
     * The supporting scan. BIR treats it as part of the books — same
     * retention and legal-hold rules as the entry it evidences (spec 03 §2).
     */
    public function attachReceipt(Request $request, VendorBill $bill): RedirectResponse
    {
        $request->validate([
            'receipt' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
        ]);

        $bill->addMedia($request->file('receipt'))->toMediaCollection(VendorBill::RECEIPTS);

        return back()->with('success', 'Receipt attached.');
    }
}
