<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Ledger\Exceptions\LedgerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Collections and disbursements (docs/specs/02 §4.2).
 *
 * Cash is applied to documents through allocations; whatever is left over is
 * an advance, and the posting rule books it as a deposit rather than letting
 * it distort a control account.
 */
class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = Payment::query()
            ->with('partner:id,registered_name,code')
            ->when($request->string('direction')->toString(), fn ($q, $d) => $q->where('direction', $d))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'direction' => $request->string('direction')->toString(),
        ]);
    }

    public function create(Request $request): Response
    {
        $direction = $request->string('direction')->toString() ?: 'received';

        return Inertia::render('Payments/Create', [
            'direction' => $direction,
            'partners' => Partner::query()
                ->where($direction === 'received' ? 'is_customer' : 'is_vendor', true)
                ->where('is_active', true)
                ->orderBy('registered_name')
                ->get(['id', 'code', 'registered_name', 'default_atc_code']),
            'cashAccounts' => DB::table('accounts as a')
                ->join('account_types as t', 't.id', '=', 'a.account_type_id')
                ->where('t.code', 'asset')
                ->where('a.is_postable', true)
                ->where('a.is_active', true)
                ->where('a.code', 'like', '10%')
                ->orderBy('a.code')
                ->get(['a.id', 'a.code', 'a.name']),
        ]);
    }

    /** Open documents for a partner, so the UI can offer them for allocation. */
    public function openDocuments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'direction' => ['required', 'in:received,paid'],
        ]);

        $balances = app(DocumentBalances::class);

        $documents = $data['direction'] === 'received'
            ? SalesInvoice::query()->where('partner_id', $data['partner_id'])->whereNot('status', 'cancelled')->get()
            : VendorBill::query()->where('partner_id', $data['partner_id'])->whereNot('status', 'cancelled')->get();

        return response()->json(
            $documents
                ->map(fn ($document) => [
                    'type' => $document instanceof SalesInvoice ? 'sales_invoice' : 'vendor_bill',
                    'id' => $document->id,
                    'number' => $document->invoice_number ?? $document->reference ?? $document->bill_number,
                    'date' => ($document->invoice_date ?? $document->bill_date)->toDateString(),
                    'total_centavos' => (int) $document->total_centavos,
                    'outstanding_centavos' => $balances->outstandingCentavos($document),
                ])
                ->filter(fn (array $row) => $row['outstanding_centavos'] > 0)
                ->values()
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:received,paid'],
            'partner_id' => ['required', 'exists:partners,id'],
            'payment_date' => ['required', 'date'],
            'amount_centavos' => ['required', 'integer', 'min:1'],
            'ewt_centavos' => ['nullable', 'integer', 'min:0'],
            'atc_code' => ['nullable', 'string', 'max:16'],
            'cash_account_id' => ['required', 'exists:accounts,id'],
            'memo' => ['nullable', 'string', 'max:500'],
            'allocations' => ['array'],
            'allocations.*.allocatable_type' => ['required', 'in:sales_invoice,vendor_bill'],
            'allocations.*.allocatable_id' => ['required', 'integer'],
            'allocations.*.applied_centavos' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $payment = DB::transaction(function () use ($data) {
                $payment = Payment::create([
                    'direction' => $data['direction'],
                    'partner_id' => $data['partner_id'],
                    'payment_date' => $data['payment_date'],
                    'amount_centavos' => $data['amount_centavos'],
                    'ewt_centavos' => $data['ewt_centavos'] ?? 0,
                    'atc_code' => $data['atc_code'] ?? null,
                    'cash_account_id' => $data['cash_account_id'],
                    'memo' => $data['memo'] ?? null,
                    'status' => 'posted',
                ]);

                foreach ($data['allocations'] ?? [] as $allocation) {
                    PaymentAllocation::create($allocation + ['payment_id' => $payment->id]);
                }

                app(DocumentPoster::class)->post($payment->load('allocations'));

                return $payment;
            });
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('payments.index')
            ->with('success', "Payment {$payment->fresh()->payment_number} posted.");
    }
}
