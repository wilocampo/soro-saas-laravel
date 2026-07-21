<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers and vendors (docs/specs/02, 03 §4). The BIR identity fields are
 * not optional decoration: a missing or malformed TIN on an invoice costs
 * the buyer its input-VAT claim, so they are validated here too.
 */
class PartnerController extends Controller
{
    public function index(Request $request): Response
    {
        $partners = Partner::query()
            ->when($request->string('role')->toString() === 'customer', fn ($q) => $q->where('is_customer', true))
            ->when($request->string('role')->toString() === 'vendor', fn ($q) => $q->where('is_vendor', true))
            ->orderBy('registered_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Partners/Index', [
            'partners' => $partners,
            'role' => $request->string('role')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Partner::create($this->validated($request));

        return back()->with('success', 'Partner created.');
    }

    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $partner->update($this->validated($request, $partner));

        return back()->with('success', 'Partner updated.');
    }

    /**
     * Deactivated, never deleted: a partner is referenced by posted
     * documents, and those are immutable (CLAUDE.md #4).
     */
    public function destroy(Partner $partner): RedirectResponse
    {
        $partner->update(['is_active' => false]);

        return back()->with('success', "{$partner->registered_name} deactivated.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Partner $partner = null): array
    {
        $unique = 'unique:partners,code'.($partner ? ",{$partner->id}" : '');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', $unique],
            'registered_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'is_customer' => ['boolean'],
            'is_vendor' => ['boolean'],
            // Nine digits; the branch code is a separate field (spec 03 §4).
            'tin' => ['nullable', 'string', 'regex:/^\d{3}-?\d{3}-?\d{3}$/'],
            'branch_code' => ['nullable', 'string', 'max:5'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_vat_registered' => ['boolean'],
            'taxpayer_type' => ['required', 'in:individual,juridical'],
            'default_atc_code' => ['nullable', 'string', 'max:16'],
            'payment_terms_days' => ['integer', 'min:0', 'max:365'],
            // Absent or expired, the HIGHER withholding rate applies (01 §7).
            'sworn_declaration_valid_until' => ['nullable', 'date'],
        ], [
            'tin.regex' => 'The TIN must be nine digits — the branch code goes in its own field.',
        ]);

        $data['tin'] = $data['tin'] === null ? null : preg_replace('/\D/', '', $data['tin']);
        $data['branch_code'] = $data['branch_code'] ?: '000';

        if (! ($data['is_customer'] ?? false) && ! ($data['is_vendor'] ?? false)) {
            abort(422, 'A partner must be a customer, a vendor, or both.');
        }

        return $data;
    }
}
