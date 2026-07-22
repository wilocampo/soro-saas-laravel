<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\LotLedger;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\StockCountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The physical count screens (docs/specs/08 §4.1), plus the expiry watch
 * list, which is the other thing a stockroom actually looks at.
 */
class StockCountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Counts/Index', [
            'counts' => DB::table('stock_counts as c')
                ->join('locations as l', 'l.id', '=', 'c.location_id')
                ->orderByDesc('c.id')
                ->paginate(15)
                ->through(fn ($row) => (array) $row),
            'locations' => DB::table('locations')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            'expiring' => app(LotLedger::class)->expiringBy(
                CarbonImmutable::now()->addDays(30)->toDateString()
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'is_blind' => ['boolean'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $service = app(StockCountService::class);
        $count = $service->open((int) $data['location_id'], $data['is_blind'] ?? true, $data['memo'] ?? null);
        $service->startCounting($count);

        return redirect()->route('counts.show', $count)->with('success', 'Counting started.');
    }

    public function show(StockCount $count): Response
    {
        $lines = DB::table('stock_count_lines as cl')
            ->join('items as i', 'i.id', '=', 'cl.item_id')
            ->where('cl.stock_count_id', $count->id)
            ->orderBy('i.code')
            ->get([
                'cl.id', 'cl.item_id', 'cl.counted_qty', 'cl.variance_qty',
                'cl.variance_centavos', 'cl.unit_cost', 'cl.note',
                'i.code', 'i.name', 'cl.snapshot_qty',
            ]);

        // A blind count must not leak the system quantity to the counter,
        // or the count stops being independent evidence (08 §4.1).
        $hideSnapshot = $count->is_blind && $count->status === 'counting';

        return Inertia::render('Counts/Show', [
            'count' => $count,
            'lines' => $lines->map(function ($line) use ($hideSnapshot) {
                $row = (array) $line;

                if ($hideSnapshot) {
                    unset($row['snapshot_qty'], $row['variance_qty'], $row['variance_centavos']);
                }

                return $row;
            }),
            'hideSnapshot' => $hideSnapshot,
        ]);
    }

    public function record(Request $request, StockCount $count): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'exists:items,id'],
            'counted_qty' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            app(StockCountService::class)->recordCount(
                $count, (int) $data['item_id'], (string) $data['counted_qty'], $data['note'] ?? null
            );
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function review(StockCount $count): RedirectResponse
    {
        try {
            app(StockCountService::class)->submitForReview($count);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ready for review.');
    }

    /** One-way: approving posts the adjustment and freezes the count. */
    public function approve(StockCount $count): RedirectResponse
    {
        try {
            $adjustment = app(StockCountService::class)->approve($count);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $adjustment === null
            ? 'Count approved — no variance to post.'
            : "Count approved; adjustment {$adjustment->reference} posted.");
    }
}
