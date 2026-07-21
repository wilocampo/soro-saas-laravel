<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Documents\AgingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A/R and A/P aging (docs/specs/02 §4.2). Read from the SUBLEDGER, so a
 * cash-basis registrant — whose books hold no A/R control account at all —
 * can still see who owes what. Carries the mandatory BIR report header
 * (RMC 5-2021 Annex B item 4).
 */
class AgingController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'as_of' => ['nullable', 'date'],
            'kind' => ['nullable', 'in:receivables,payables'],
        ]);

        $asOf = CarbonImmutable::parse($request->string('as_of')->toString() ?: 'now');
        $kind = $request->string('kind')->toString() ?: 'receivables';

        $aging = $kind === 'payables'
            ? app(AgingService::class)->payables($asOf)
            : app(AgingService::class)->receivables($asOf);

        return Inertia::render('Reports/Aging', [
            'aging' => $aging,
            'kind' => $kind,
            'header' => app(ReportHeader::class)->for(
                $kind === 'payables' ? 'Accounts Payable Aging' : 'Accounts Receivable Aging'
            ),
            'buckets' => array_keys(AgingService::BUCKETS),
        ]);
    }
}
