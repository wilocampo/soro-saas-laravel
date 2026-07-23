# 08 — Inventory Module (user-friendly encoding, variance detection, ledger-integrated)

Scope change 2026-07-21: inventory is **IN scope** (reverses D8 — see `06-decisions.md`). Goals set by the user: *user-friendly yet advanced*, **identify variances**, **make encoding receives easy**. Design mines the developer's own prior art (ninetails `app-modules/inventory` — lot/FEFO layer, UoM conversion cascade) — lift what worked, fix what didn't.

## Locked decisions (user, 2026-07-21)
- **Costing: moving weighted average** — recompute average cost on every receipt; COGS at average. (Lot-level actual cost is still *captured* per receipt for traceability, but COGS uses the moving average.)
- **COGS timing: perpetual** — Dr COGS / Cr Inventory posts automatically on every sale at cost.
- **Locations: multi-location v1** — a `location_id` dimension on all movements + transfer documents.
- **Lots/expiry: v1** — per-item tracking toggle (`none|lot`), expiry dates, FEFO suggestions, spoilage adjustments.

## Design philosophy — same invariants as the ledger
The stock ledger mirrors the journal exactly:
1. **`stock_movements` is append-only and the only source of truth for quantity.** Corrections are opposite movements, never edits/deletes (same immutability triggers pattern as `journal_lines`).
2. **On-hand is derived**, cached in `item_location_balances`, rebuildable by command. *(Explicitly rejecting the ninetails pain: on-hand there is a 7-arm `UNION ALL` recomputed per read — 6–20 s scans at 619k rows. Persist movements + cache from day one.)*
3. Every inventory document that has a money effect **posts to the journal via `PostingService`** — inventory value on the balance sheet is always explainable as Σ(movements × cost).
4. Quantities are `DECIMAL(16,5)` (subledger precision is allowed per `01` §0); average cost is `DECIMAL(19,6)` pesos in the subledger; **only rounded centavo totals post to the journal** (largest-remainder on splits).

## 1. Data model (tenant DB, `database/migrations/tenant/`)

- **`uoms`** (`name`, `symbol`) + **`item_uom_conversions`** (`item_id`, `from_uom_id`, `to_uom_id`, `factor DECIMAL(15,10)`).
- **`locations`** (`code`, `name`, `type warehouse|store`, `is_active`). v1 ships one default location per tenant; the dimension exists everywhere from day one.
- **`items`** — `code`, `name`, `item_type ENUM(inventory, service, non_inventory)`, `stock_uom_id`, `purchase_uom_id`, `purchase_to_stock_factor DECIMAL(16,5)`, `tracking ENUM(none,lot)`, `track_expiry`, `require_expiry`, `reorder_point`, `is_active`; **per-item GL bindings** (lifted from ninetails — good pattern): `inventory_account_id`, `income_account_id`, `cogs_account_id`, `adjustment_account_id`; costing: `avg_cost DECIMAL(19,6)`, `last_cost DECIMAL(19,6)`.
- **`item_barcodes`** (`item_id`, `barcode` UNIQUE, `packaging_level ENUM(EACH,PACK,CASE)`, `uom_id`) — scan-to-add resolves barcode → item + purchase UoM.
- **`item_vendors`** (`vendor_id`, `vendor_sku`, `vendor_item_name`, `pack_uom_id`, `default_conversion_factor`, `last_price`, `preferred`) — feeds the UoM defaulting cascade.
- **`stock_movements`** (append-only; immutability triggers): `item_id`, `location_id`, `movement_type ENUM(receive, sale, adjust_in, adjust_out, transfer_in, transfer_out, count_adjust, reversal)`, `qty_delta DECIMAL(16,5)` signed, `unit_cost DECIMAL(19,6)` (cost context at movement time), `lot_id` NULL, `source_type`/`source_id` (document), `journal_entry_id` NULL (the JE this movement's money effect posted to), `moved_at`, `created_by`. Index `(item_id, location_id, moved_at)`.
- **`item_location_balances`** (derived cache): `item_id`, `location_id`, `qty_on_hand DECIMAL(16,5)`, `rebuilt_at`. Upserted per movement under `lockForUpdate()`; rebuildable via `php artisan inventory:rebuild-balances`; verified nightly by `inventory:verify` (movements sum = cache) alongside `ledger:verify`.
- **`inventory_lots`** + **`lot_movements`** — lift the ninetails design nearly verbatim (its best part): lot header (`lot_code`, `mfg_date`, `expiry_date`, `received_qty`, `cost_per_base`, `status active|consumed`) with `remaining_qty` **derived** = Σ`lot_movements.qty_delta` recomputed under `lockForUpdate()`; append-only `lot_movements` (`reason receive|release|adjustment|reversal|expiry`); **idempotent `reverse(source)` keyed to net-outstanding-per-lot** (their single best pattern — makes delete/restore safe under double-fire). Fix their known gap: **positive adjustments create a lot** (an "adjustment lot" with the item's current avg cost) so found stock is FEFO-consumable.
- **Documents:** `goods_receipts` + lines (v1: direct receive; receive-against-PO arrives with POs in v2 — but lines carry `po_line_ref` NULL now); `stock_counts` + lines (`snapshot_qty` frozen at count start, `counted_qty`, `variance_qty` computed); `stock_adjustments` + lines (reason-coded: shrinkage, spoilage, damage, found, correction); `stock_transfers` + lines (location → location; no GL effect, quantity-only unless locations map to different GL accounts — v1: no GL effect).

## 2. Costing — moving weighted average (integer-safe)
On each receipt, per item (global across locations in v1 — per-location costing is v2; see decision D14):
```
new_avg = (on_hand_qty × avg_cost + received_qty × unit_cost) / (on_hand_qty + received_qty)
```
computed in DECIMAL(19,6) under a per-item `SELECT ... FOR UPDATE` (serializes concurrent receipts of the same item). Negative or zero on-hand at receipt time → avg resets to the incoming unit cost (standard practice; flag in variance report). `items.last_cost` also updated (kept for purchase-price-variance reporting, *not* for COGS — fixes the ninetails bifurcation where lot costs were captured but last-cost drove valuation).

**COGS at sale (perpetual):** the sales-invoice `PostingRule` extends — for each inventory-type line: `cogs_centavos = round(qty × avg_cost)` (largest-remainder across lines) → adds `Dr COGS (item.cogs_account) / Cr Inventory (item.inventory_account)` lines to the *same* journal entry as the revenue posting. Cash-basis tenants: COGS posts at the same event revenue is recognized (collection), from the invoice's captured cost snapshot.

**Negative stock policy:** per-tenant setting — `block` (default; sale rejected when insufficient on-hand at the location) or `warn` (allow, flag on variance dashboard). BIR-clean books favor `block`.

## 3. Posting rules (extend `02-posting-engine.md`)
| Event | Journal (accrual) |
|---|---|
| Goods receipt (with vendor bill) | Dr Inventory (at actual unit cost) + Dr Input VAT / Cr A/P (+ Cr WHT Payable if EWT) |
| Goods receipt (no bill yet) | Dr Inventory / Cr **GRNI clearing** (Goods Received Not Invoiced); bill later clears GRNI → A/P. *(GRNI account = new system account in `ledger_settings`.)* |
| Sale of inventory item | (revenue lines per `02` §4.1) **+ Dr COGS / Cr Inventory at avg cost** |
| Count/shrinkage adjustment (short) | Dr Shrinkage/Spoilage expense (`item.adjustment_account`) / Cr Inventory |
| Count adjustment (over/found) | Dr Inventory / Cr Shrinkage expense (or Other Income — CPA to confirm) |
| Transfer between locations | quantity-only; no JE (v1) |

All flow through `PostingService` with idempotency keys (`goods_receipt:{id}:post:1`, etc.). Voiding an inventory document reverses **both** its JE (mirror lines) and its stock movements (opposite deltas) in one transaction, using the idempotent lot-reversal pattern.

## 4. Variance identification (the "advanced" part)
1. **Physical count variance** — the core workflow: create a count (scope = location + optional category/item filter) → system freezes `snapshot_qty` per line at count start → staff enter `counted_qty` (mobile-friendly, barcode scan to jump to item, blind-count mode hides system qty as a per-count option) → `variance_qty = counted − snapshot`, valued at avg cost → review screen ranks by absolute peso variance → approve → generates a `stock_adjustment` + JE + (for shorts) FEFO lot draw-down with server-side re-validation. Count lifecycle: `draft → counting → review → approved` (approved is immutable).
2. **Receiving variance** — at receive time: line-level flags when `unit_cost` deviates > x% from `last_cost`/`item_vendors.last_price` (catch mis-keys at encode time, the cheapest moment); receipt-vs-bill match when the bill arrives (qty/price deltas surface before A/P posts).
3. **Shrinkage trend report** — adjustments by reason code × item × location × period, valued; spoilage subtotaled (CPA note: BIR deductibility of spoilage may require notice/inspection — sign-off item).
4. **Integrity variance** — `inventory:verify` (nightly): cache vs movements, lot `remaining_qty` vs lot movements, inventory GL account balance vs Σ(on-hand × avg cost) — the *book-to-ledger tie-out* that proves the subledger and GL agree; drift is a sev-1 like a hash-chain break.

## 5. Receiving UX — "easy encoding" (lift the proven ninetails patterns)
- **Item picker + barcode**: autocomplete picker; wire the barcode lookup (`GET /items/barcodes/lookup/{code}`) into the page's keyboard loop — scan → line appears with purchase UoM defaulted (close the gap ninetails left: their endpoint existed but wasn't wired into receive).
- **UoM conversion cascade** (their best UX idea — reimplement `resolveFactor()`): item conversion match → preferred vendor pack factor → item `purchase_to_stock_factor` → 1; **live inline preview** "`{qty} {purchase unit} = {base} {stock unit}`" with an amber warning when a lot-tracked line lacks a factor.
- **Lot sub-row**: lot-tracked lines expand inline for `lot_code`/`expiry_date`; `require_expiry` items **rejected pre-write** if the date is missing (batch pre-validation before any row persists).
- **Same defaulting path for every row source** (picker, barcode, future PO-prefill) — explicit ninetails design goal, keep it.
- Keyboard-first grid (Enter = next line), sticky totals bar with qty + value, draft autosave.

## 6. BIR touchpoints (feeds `03-bir-accreditation.md`)
- **Inventory Book** joins the books-of-accounts output (RMC 5-2021 Annex B item 3(e)) — generated from `stock_movements` + valuations, with the mandatory header/footer.
- **Annual Inventory List** submission (due ~30 days after FY close) — generated from the FY-end count/valuation.
- **Costing-method consistency** — ✅ **CPA-confirmed 2026-07-24 (D39):** weighted average is fine, is **disclosed in the FS/ITR**, and **a change of method requires PRIOR BIR consent**. Build consequence: the costing method **locks at go-live** and may only be changed against a recorded consent reference — it is not a settings toggle.
- **Spoilage/shrinkage deductibility** — ✅ **CPA-answered 2026-07-24 (D39):** deductible only through the **RMO 21-2020 destruction/disposal process**. Build consequence: a write-off needs an associated destruction record (application, schedule, BIR witness / Certificate of Deduction) before it counts as deductible, and the shrinkage report must **separate documented from undocumented losses** — an undocumented write-off is a book expense that is not a tax deduction, and the report should not let those look alike.
- **⛔ Cash basis is unavailable to inventory-carrying tenants** (D38, CPA 2026-07-24). This retires the Deferred COGS path (account 1450 and `CostOfSales::lines(..., deferred: true)`), which existed only to serve that combination. Enforce at settings level; the dead branch is pending removal.

## 7. What we deliberately do NOT copy from ninetails
- The overloaded `transactions` table with magic `trans_type_id` integers — every document type gets its own table here.
- Trigger-assigned UUID PKs and the insert-then-re-read dance — normal auto-increment PKs + ULIDs where external refs are needed.
- Last-cost-as-valuation — moving average is the single costing truth; last cost is informational.
- Balance-less on-hand (UNION-derived) — movements + cached balances from day one.

## Phase placement
Inventory lands as **Phase 2b** (after core documents exist, since COGS hooks the sales invoice rule): items/UoM/locations/barcodes → receiving (+GRNI) → perpetual COGS → transfers → counts/variance → lots/FEFO/expiry. Exit criteria: receive→sell→count round-trip posts correct JEs; `inventory:verify` ties subledger to GL to the centavo; count variance produces an approved, immutable adjustment + JE; FEFO suggestions re-validated server-side.

## New open decisions (also in `06`)
- **D14** Per-item global vs per-location average cost (v1: global; revisit v2).
- **D15** GRNI flow in v1 vs "receive always creates the bill" (ninetails treated receive=bill; GRNI is cleaner — default GRNI).
- **D16** ✅ **RESOLVED (CPA, 2026-07-24 — briefing Q20):** found stock credits **Inventory Shrinkage**. Net-to-shrinkage is standard and keeps the trend report honest. Built as-is; no change.
- **D17** Negative stock: block (default) vs warn.
