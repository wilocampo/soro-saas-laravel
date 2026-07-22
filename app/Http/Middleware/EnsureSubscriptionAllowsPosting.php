<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The billing gate (docs/specs/10, Phase 5).
 *
 * Applied to WRITE routes only, and that asymmetry is the whole design: a
 * lapsed subscription must never hide a taxpayer's own books. BIR holds the
 * REGISTRANT responsible for keeping and producing them, so locking a
 * non-paying tenant out of its records would put that taxpayer in breach
 * through no act of their own — and would make us the reason.
 *
 * So reading, printing and EXPORTING stay open forever. Only new postings
 * stop. A tenant that leaves can always take its books with it.
 */
class EnsureSubscriptionAllowsPosting
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = currentTenant();

        if ($tenant === null || $tenant->canPost()) {
            return $next($request);
        }

        $message = 'This subscription has lapsed, so no new entries can be posted. '
            .'Your books stay readable and exportable — renew to resume posting.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 402);
        }

        return back()->with('error', $message);
    }
}
