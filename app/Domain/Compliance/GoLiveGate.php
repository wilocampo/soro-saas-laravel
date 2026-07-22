<?php

namespace App\Domain\Compliance;

use App\Domain\Ledger\Models\CompanyProfile;
use Illuminate\Support\Facades\DB;

/**
 * The go-live checklist (docs/specs/03 §1, 10 §2 — Phase 5).
 *
 * Two of these are legal gates, not preferences:
 *
 *  1. **The BIR Acknowledgement Certificate (ACCN).** Post-EOPT a CAS is
 *     registered by submission and receives an AC (RMC 5-2021, RMO 9-2021).
 *     Issuing system-generated invoices from an unregistered CAS is what
 *     the penalties in spec 03 §8 are about. We cannot verify an ACCN with
 *     BIR, so the gate is an ATTESTATION: the operator records the number
 *     and date, and the system stamps it on every document thereafter.
 *  2. **NPC registration (RA 10173).** A system holding this much personal
 *     data — counterparty TINs, addresses, 2307s, alphalists — needs the
 *     tenant's own DPA compliance in place; we record that they have it.
 *
 * The rest are operational readiness. `blocking()` returns only the items
 * that should stop a tenant from issuing real documents; everything else is
 * advice. Nothing here silently prevents work — the UI shows the list and
 * the operator decides, because we are not the tenant's compliance officer.
 */
class GoLiveGate
{
    /**
     * @return array{
     *   ready:bool, blocking:list<array<string,mixed>>, advisory:list<array<string,mixed>>
     * }
     */
    public function check(): array
    {
        $profile = CompanyProfile::current();
        $settings = DB::table('ledger_settings')->where('id', 1)->first();

        $items = [
            [
                'key' => 'company_profile',
                'blocking' => true,
                'done' => ! $profile->isUnconfigured(),
                'title' => 'Registered name, address and TIN',
                'detail' => 'Printed on every invoice and on the header of every book (RMC 5-2021 Annex B item 4).',
            ],
            [
                'key' => 'accn',
                'blocking' => true,
                'done' => $profile->isAccredited(),
                'title' => 'BIR Acknowledgement Certificate number',
                'detail' => 'A CAS must be registered before it issues documents. Record the ACCN and its date; '
                    .'we stamp it on generated output but cannot verify it with BIR for you.',
            ],
            [
                'key' => 'npc_registration',
                'blocking' => true,
                'done' => (bool) ($profile->npc_registered ?? false),
                'title' => 'NPC / Data Privacy Act registration',
                'detail' => 'This system holds counterparty TINs, addresses and alphalist data. Confirm your '
                    .'DPA registration and appointed Data Protection Officer (RA 10173).',
            ],
            [
                'key' => 'accounting_basis',
                'blocking' => true,
                'done' => $settings?->accounting_basis !== null,
                'title' => 'Accounting basis',
                'detail' => 'Accrual or cash, matching your BIR registration. Changing it later is a '
                    .'restatement, not a toggle (D2).',
            ],
            [
                'key' => 'opening_balances',
                'blocking' => false,
                'done' => DB::table('journal_entries')->where('journal_book', 'opening_balance')->exists(),
                'title' => 'Opening balances posted',
                'detail' => 'Carry in your prior balances so the first report is complete.',
            ],
            [
                'key' => 'chart_of_accounts',
                'blocking' => false,
                'done' => DB::table('accounts')->where('is_system', false)->count() > 15,
                'title' => 'Chart of accounts reviewed',
                'detail' => 'The seeded chart is a starting point; add what your business actually uses.',
            ],
            [
                'key' => 'serial_start',
                'blocking' => false,
                'done' => DB::table('serial_sequences')->where('last_value', '>', 0)->exists(),
                'title' => 'Invoice serial continues your prior series',
                'detail' => 'On migration the series continues rather than restarting (RMC 77-2024). Set the '
                    .'starting value before issuing the first invoice.',
            ],
        ];

        $blocking = array_values(array_filter($items, fn (array $i) => $i['blocking'] && ! $i['done']));
        $advisory = array_values(array_filter($items, fn (array $i) => ! $i['blocking'] && ! $i['done']));

        return [
            'ready' => $blocking === [],
            'blocking' => $blocking,
            'advisory' => $advisory,
            'items' => $items,
        ];
    }
}
