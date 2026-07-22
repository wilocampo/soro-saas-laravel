<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Reports\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual bank reconciliation (Phase 3; bank feeds are v2). The controller
 * offers no adjustment field, because the service accepts none: a difference
 * is an unbooked bank item and the answer is to post it.
 */
class BankReconciliationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reconciliations/Index', [
            'reconciliations' => DB::table('bank_reconciliations as r')
                ->join('accounts as a', 'a.id', '=', 'r.cash_account_id')
                ->orderByDesc('r.statement_date')
                ->get([
                    'r.id', 'r.statement_date', 'r.statement_closing_centavos', 'r.status',
                    'r.notes', 'r.completed_at', 'a.code', 'a.name',
                ]),
            'cashAccounts' => $this->cashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'exists:accounts,id'],
            'statement_date' => ['required', 'date'],
            // Signed: an overdrawn account legitimately shows a credit balance.
            'statement_closing_centavos' => ['required', 'integer'],
        ]);

        try {
            $id = app(BankReconciliationService::class)->open(
                (int) $data['cash_account_id'],
                $data['statement_date'],
                (int) $data['statement_closing_centavos'],
            );
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reconciliations.show', $id);
    }

    public function show(int $reconciliation): Response
    {
        return Inertia::render('Reconciliations/Show', [
            'summary' => app(BankReconciliationService::class)->summary($reconciliation),
        ]);
    }

    public function toggle(Request $request, int $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'journal_line_id' => ['required', 'integer', 'exists:journal_lines,id'],
            'cleared' => ['required', 'boolean'],
        ]);

        try {
            app(BankReconciliationService::class)->setCleared(
                $reconciliation,
                (int) $data['journal_line_id'],
                (bool) $data['cleared'],
            );
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function complete(Request $request, int $reconciliation): RedirectResponse
    {
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:500']])['notes'] ?? null;

        try {
            app(BankReconciliationService::class)->complete($reconciliation, $notes);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reconciliation completed.');
    }

    /** @return Collection<int, \stdClass> */
    private function cashAccounts()
    {
        return DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('t.code', 'asset')
            ->where('a.is_postable', true)
            ->where('a.is_active', true)
            ->where('a.code', 'like', '10%')
            ->orderBy('a.code')
            ->get(['a.id', 'a.code', 'a.name']);
    }
}
