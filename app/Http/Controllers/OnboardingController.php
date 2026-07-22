<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\GoLiveGate;
use App\Domain\Ledger\AuditLogger;
use App\Domain\Ledger\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant onboarding and the go-live gate (docs/specs/03 §1, 10 §2).
 *
 * The gate is deliberately advisory rather than a hard block: we are not
 * the tenant's compliance officer, and a system that silently refuses to
 * work is worse than one that says clearly what is missing. What it DOES do
 * is refuse to pretend — an unregistered CAS gets a warning on every
 * document, and declaring go-live is an audited act with a name against it.
 */
class OnboardingController extends Controller
{
    public function show(): Response
    {
        $profile = CompanyProfile::current();

        return Inertia::render('Onboarding/Show', [
            'checklist' => app(GoLiveGate::class)->check(),
            'profile' => $profile->only([
                'registered_name', 'registered_address', 'tin', 'branch_code',
                'taxpayer_classification', 'is_twa', 'twa_effective_date',
                'accn', 'accn_issued_at', 'registration_mode',
                'npc_registered', 'npc_registration_number', 'dpo_name', 'dpo_email', 'go_live_at',
            ]),
            'settings' => DB::table('ledger_settings')->where('id', 1)
                ->first(['accounting_basis', 'is_vat_registered', 'percentage_tax_rate_bp']),
            'placeholderTin' => CompanyProfile::PLACEHOLDER_TIN,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registered_name' => ['required', 'string', 'max:255'],
            'registered_address' => ['required', 'string', 'max:500'],
            'tin' => ['required', 'string', 'regex:/^\d{3}-?\d{3}-?\d{3}$/'],
            'branch_code' => ['nullable', 'string', 'max:5'],
            'taxpayer_classification' => ['nullable', 'in:micro,small,medium,large'],
            'is_twa' => ['boolean'],
            'twa_effective_date' => ['nullable', 'date'],
            'accn' => ['nullable', 'string', 'max:64'],
            'accn_issued_at' => ['nullable', 'date', 'required_with:accn'],
            'registration_mode' => ['nullable', 'in:manual,loose_leaf,cba,cas'],
            'npc_registered' => ['boolean'],
            'npc_registration_number' => ['nullable', 'string', 'max:64'],
            'dpo_name' => ['nullable', 'string', 'max:255'],
            'dpo_email' => ['nullable', 'email', 'max:255'],
        ], [
            'tin.regex' => 'The TIN must be nine digits — the branch code goes in its own field.',
            'accn_issued_at.required_with' => 'Record the date the Acknowledgement Certificate was issued.',
        ]);

        // A DPA attestation without a named DPO is not an attestation.
        if (($data['npc_registered'] ?? false) && ($data['dpo_name'] ?? '') === '') {
            return back()->with('error', 'Name your Data Protection Officer before confirming NPC registration.');
        }

        $data['tin'] = preg_replace('/\D/', '', $data['tin']);
        $data['branch_code'] = $data['branch_code'] ?: '000';

        $profile = CompanyProfile::current();
        $before = $profile->only(array_keys($data));

        $profile->update($data);

        // The registration details are stamped on every legal document this
        // system issues, so a change to them is an audited event.
        app(AuditLogger::class)->record(
            event: 'company_profile.updated',
            auditableType: 'company_profile',
            auditableId: 1,
            documentNumber: $data['accn'] ?? null,
            before: $before,
            after: $data,
        );

        return back()->with('success', 'Registration details saved.');
    }

    /**
     * Declare the tenant ready to issue real documents.
     *
     * Audited, with a name against it: this is the moment someone takes
     * responsibility for the attestations above.
     */
    public function goLive(): RedirectResponse
    {
        $checklist = app(GoLiveGate::class)->check();

        if (! $checklist['ready']) {
            $missing = implode(', ', array_column($checklist['blocking'], 'title'));

            return back()->with('error', "Still outstanding: {$missing}.");
        }

        $profile = CompanyProfile::current();

        if ($profile->go_live_at !== null) {
            return back()->with('success', 'Already live.');
        }

        $profile->update(['go_live_at' => now()]);

        app(AuditLogger::class)->record(
            event: 'tenant.went_live',
            auditableType: 'company_profile',
            auditableId: 1,
            documentNumber: $profile->accn,
            after: [
                'accn' => $profile->accn,
                'npc_registration_number' => $profile->npc_registration_number,
                'dpo_name' => $profile->dpo_name,
            ],
        );

        return back()->with('success', 'Go-live recorded. Documents issued from now on are live records.');
    }
}
