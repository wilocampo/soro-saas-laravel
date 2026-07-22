<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The item master (docs/specs/08 §1) plus the barcode lookup the receiving
 * grid scans into.
 */
class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        $stock = app(StockLedger::class);

        $items = Item::query()
            ->when($request->string('type')->toString(), fn ($q, $type) => $q->where('item_type', $type))
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('item_type', 'inventory'))
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Item $item) => [
                ...$item->only([
                    'id', 'code', 'name', 'item_type', 'tracking', 'track_expiry',
                    'avg_cost', 'last_cost', 'reorder_point', 'is_active',
                ]),
                'on_hand' => $item->isStocked() ? $stock->onHandEverywhere($item->id) : null,
                // Valued at the moving average, so this column agrees with
                // the balance sheet.
                'value_centavos' => $item->isStocked()
                    ? (int) round((float) $stock->onHandEverywhere($item->id) * (float) $item->avg_cost * 100)
                    : null,
            ]);

        return Inertia::render('Items/Index', [
            'items' => $items,
            'type' => $request->string('type')->toString(),
            'uoms' => DB::table('uoms')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'symbol']),
            'accounts' => $this->postableAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Item::create($this->validated($request));

        return back()->with('success', 'Item created.');
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validated($request, $item);

        // Costing is maintained by the stock ledger, never by hand: a typed
        // average would silently restate every future COGS posting.
        unset($data['avg_cost'], $data['last_cost']);

        $item->update($data);

        return back()->with('success', 'Item updated.');
    }

    /** Deactivated, never deleted — posted movements reference it. */
    public function destroy(Item $item): RedirectResponse
    {
        $item->update(['is_active' => false]);

        return back()->with('success', "{$item->code} deactivated.");
    }

    /**
     * Barcode → item + the UoM it was scanned in.
     *
     * Spec 08 §5 calls out that the prior art had this endpoint but never
     * wired it into receiving; here the receiving grid calls it directly on
     * every scan.
     */
    public function lookupBarcode(string $barcode): JsonResponse
    {
        $row = DB::table('item_barcodes as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'b.uom_id')
            ->where('b.barcode', $barcode)
            ->where('i.is_active', true)
            ->first([
                'i.id', 'i.code', 'i.name', 'i.tracking', 'i.require_expiry',
                'i.purchase_to_stock_factor', 'i.last_cost',
                'b.uom_id', 'b.packaging_level', 'u.symbol as uom_symbol',
            ]);

        if ($row === null) {
            return response()->json(['message' => "No active item carries barcode [{$barcode}]."], 404);
        }

        return response()->json($row);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Item $item = null): array
    {
        $unique = 'unique:items,code'.($item ? ",{$item->id}" : '');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', $unique],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'item_type' => ['required', 'in:inventory,service,non_inventory'],
            'stock_uom_id' => ['required', 'exists:uoms,id'],
            'purchase_uom_id' => ['nullable', 'exists:uoms,id'],
            'purchase_to_stock_factor' => ['required', 'numeric', 'gt:0'],
            'tracking' => ['required', 'in:none,lot'],
            'track_expiry' => ['boolean'],
            'require_expiry' => ['boolean'],
            'reorder_point' => ['numeric', 'min:0'],
            'inventory_account_id' => ['nullable', 'exists:accounts,id'],
            'income_account_id' => ['nullable', 'exists:accounts,id'],
            'cogs_account_id' => ['nullable', 'exists:accounts,id'],
            'adjustment_account_id' => ['nullable', 'exists:accounts,id'],
        ]);

        // An item that demands an expiry date must be tracking lots, or
        // there is nothing to attach the date to.
        if (($data['require_expiry'] ?? false) && $data['tracking'] !== 'lot') {
            abort(422, 'Only a lot-tracked item can require an expiry date.');
        }

        return $data;
    }

    /** @return Collection<int, \stdClass> */
    private function postableAccounts()
    {
        return DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('a.is_postable', true)
            ->where('a.is_active', true)
            ->orderBy('a.code')
            ->get(['a.id', 'a.code', 'a.name', 't.code as type_code']);
    }
}
