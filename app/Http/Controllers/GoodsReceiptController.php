<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\GoodsReceiptLine;
use App\Domain\Inventory\Models\Item;
use App\Domain\Ledger\Exceptions\LedgerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receiving (docs/specs/08 §5 — "make encoding receives easy").
 *
 * The UoM conversion cascade and the price-variance flag both live here
 * rather than in the client, because a hint the browser computed is not
 * evidence: the server re-derives the factor it will actually store.
 */
class GoodsReceiptController extends Controller
{
    /** Deviation beyond this share of the last price is flagged at encode time. */
    private const PRICE_VARIANCE_THRESHOLD = 0.15;

    public function index(Request $request): Response
    {
        return Inertia::render('Receipts/Index', [
            'receipts' => GoodsReceipt::query()
                ->with('partner:id,registered_name')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('received_date')->orderByDesc('id')
                ->paginate(15)->withQueryString(),
            'status' => $request->string('status')->toString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Receipts/Create', [
            'vendors' => Partner::query()->where('is_vendor', true)->where('is_active', true)
                ->orderBy('registered_name')->get(['id', 'code', 'registered_name']),
            'locations' => DB::table('locations')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            'items' => Item::query()->where('item_type', 'inventory')->where('is_active', true)
                ->orderBy('code')
                ->get([
                    'id', 'code', 'name', 'tracking', 'require_expiry',
                    'stock_uom_id', 'purchase_uom_id', 'purchase_to_stock_factor', 'last_cost',
                ]),
            'uoms' => DB::table('uoms')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'symbol']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['nullable', 'exists:partners,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'received_date' => ['required', 'date'],
            'vendor_reference' => ['nullable', 'string', 'max:64'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.qty_entered' => ['required', 'numeric', 'gt:0'],
            'lines.*.entered_uom_id' => ['required', 'exists:uoms,id'],
            'lines.*.conversion_factor' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:64'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ]);

        // Batch pre-validation: a lot-tracked line missing its required
        // expiry is rejected BEFORE any row persists (08 §5).
        $this->assertLotDataComplete($data['lines']);

        try {
            $receipt = DB::transaction(function () use ($data) {
                $receipt = GoodsReceipt::create([
                    'partner_id' => $data['partner_id'] ?? null,
                    'location_id' => $data['location_id'],
                    'received_date' => $data['received_date'],
                    'vendor_reference' => $data['vendor_reference'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'status' => 'posted',
                ]);

                foreach (array_values($data['lines']) as $index => $line) {
                    $this->createLine($receipt, $index + 1, $line);
                }

                $receipt->load('lines');
                $receipt->recalculateTotals();
                $receipt->save();

                app(DocumentPoster::class)->post($receipt);

                return $receipt;
            });
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('receipts.show', $receipt->fresh())
            ->with('success', "Receipt {$receipt->fresh()->reference} posted.");
    }

    public function show(GoodsReceipt $receipt): Response
    {
        $receipt->load(['lines.item', 'partner', 'location']);

        return Inertia::render('Receipts/Show', [
            'receipt' => $receipt,
            'journalEntry' => $receipt->journal_entry_id === null ? null : DB::table('journal_entries')
                ->where('id', $receipt->journal_entry_id)
                ->first(['id', 'entry_number', 'entry_date', 'status']),
            'lines' => $receipt->journal_entry_id === null ? [] : DB::table('journal_lines as jl')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('jl.journal_entry_id', $receipt->journal_entry_id)
                ->orderBy('jl.line_no')
                ->get(['a.code', 'a.name', 'jl.debit_centavos', 'jl.credit_centavos', 'jl.memo']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createLine(GoodsReceipt $receipt, int $lineNo, array $line): void
    {
        $item = Item::query()->findOrFail($line['item_id']);
        $factor = $this->resolveFactor($item, (int) $line['entered_uom_id'], $line['conversion_factor'] ?? null);

        $qtyStock = bcmul((string) $line['qty_entered'], $factor, 5);
        $unitCost = (string) $line['unit_cost'];

        GoodsReceiptLine::create([
            'goods_receipt_id' => $receipt->id,
            'line_no' => $lineNo,
            'item_id' => $item->id,
            'qty_entered' => $line['qty_entered'],
            'entered_uom_id' => $line['entered_uom_id'],
            'conversion_factor' => $factor,
            'qty_stock' => $qtyStock,
            'unit_cost' => $unitCost,
            'line_cost_centavos' => (int) round((float) $qtyStock * (float) $unitCost * 100),
            'lot_code' => $line['lot_code'] ?? null,
            'expiry_date' => $line['expiry_date'] ?? null,
            // Caught at the cheapest possible moment — while the operator is
            // still looking at the delivery (08 §4.2).
            'variance_note' => $this->priceVarianceNote($item, $unitCost),
        ]);
    }

    /**
     * The UoM cascade (08 §5): an explicit item conversion, then the
     * preferred vendor's pack factor, then the item's own purchase factor,
     * then 1. Re-derived server-side even when the client sent a factor,
     * unless the operator overrode it deliberately.
     */
    private function resolveFactor(Item $item, int $enteredUomId, ?string $clientFactor): string
    {
        if ($clientFactor !== null) {
            return (string) $clientFactor;   // an explicit operator override
        }

        if ($enteredUomId === (int) $item->stock_uom_id) {
            return '1';
        }

        $conversion = DB::table('item_uom_conversions')
            ->where('item_id', $item->id)
            ->where('from_uom_id', $enteredUomId)
            ->where('to_uom_id', $item->stock_uom_id)
            ->value('factor');

        if ($conversion !== null) {
            return (string) $conversion;
        }

        $vendorFactor = DB::table('item_vendors')
            ->where('item_id', $item->id)
            ->where('pack_uom_id', $enteredUomId)
            ->orderByDesc('preferred')
            ->value('default_conversion_factor');

        if ($vendorFactor !== null) {
            return (string) $vendorFactor;
        }

        return $enteredUomId === (int) $item->purchase_uom_id
            ? (string) $item->purchase_to_stock_factor
            : '1';
    }

    private function priceVarianceNote(Item $item, string $unitCost): ?string
    {
        $last = (float) $item->last_cost;

        if ($last <= 0.0) {
            return null;   // nothing to compare against yet
        }

        $deviation = abs((float) $unitCost - $last) / $last;

        if ($deviation <= self::PRICE_VARIANCE_THRESHOLD) {
            return null;
        }

        return sprintf(
            'Unit cost is %.0f%% %s the last cost of %s — check for a keying error.',
            $deviation * 100,
            (float) $unitCost > $last ? 'above' : 'below',
            number_format($last, 2),
        );
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function assertLotDataComplete(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $item = Item::query()->find($line['item_id']);

            if ($item === null || ! $item->isLotTracked()) {
                continue;
            }

            if (($line['lot_code'] ?? '') === '') {
                abort(422, "Line {$index}: {$item->code} is lot-tracked and needs a lot code.");
            }

            if ($item->require_expiry && ($line['expiry_date'] ?? '') === '') {
                abort(422, "Line {$index}: {$item->code} requires an expiry date.");
            }
        }
    }
}
