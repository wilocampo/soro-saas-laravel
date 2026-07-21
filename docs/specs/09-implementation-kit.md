# 09 — Implementation Kit: Packages, Components & Platform Decisions

Verified against Packagist/GitHub 2026-07-21 (Laravel 12 / PHP 8.2 compat + license + maintenance checked per package). Repo already has: Breeze, Cashier, Sanctum, Telescope, spatie multitenancy/permission/medialibrary/backup, nwidart-modules, Ziggy, Inertia (server v2), Pint, PHPUnit 11; npm: PrimeVue 4.3 + chart.js 4.5 + Tailwind.

## A. Composer — production (add in Phase 0/2 as needed)

| Package | Constraint | License | Why | Caveat |
|---|---|---|---|---|
| `spatie/laravel-pdf` | `^2.12` | MIT | **The PDF pick** — driver-based: Browsershot/Chrome for print-faithful BIR books/invoices (native per-page `headerTemplate`/`footerTemplate` = the RMC 5-2021 mandatory header/footer), DOMPDF driver as pure-PHP fallback for constrained VPS installs | Browsershot needs Node + puppeteer + Chromium on the host — budget into the Docker image |
| `spatie/browsershot` | `^5.0` | MIT | Chrome driver for the above | — |
| `openspout/openspout` | `^4.28` | MIT | Streaming XLSX/CSV writer, <3 MB memory regardless of size — books can be huge | Pins PHP minors; on PHP 8.2 composer resolves 4.x — bump with the PHP floor |
| `league/csv` | `^9.28` | MIT | CSV + delimited foundation for RR 16-2006 exports and the hand-built BIR `.DAT` writers | — |
| `endroid/qr-code` | `^6.0` | MIT | ORUS QR stamp + invoice QR | **Pin `^6.0`** — 6.1+ requires PHP ^8.4 |
| `sentry/sentry-laravel` | `^4.15` | MIT | Production error tracking (Telescope is dev-only); self-hostable — matters for single-tenant VPS installs + data residency | — |

**PDF rationale:** `mpdf` **rejected** — GPL-2.0-only, and `SINGLE_TENANT` per-client-VPS mode *distributes* the app (same license discipline that rejected Akaunting). `barryvdh/laravel-dompdf` never added separately — it comes free as spatie/laravel-pdf's fallback driver. Mitigate huge-book rendering: chunk per month/quarter on the queue, cap Chrome concurrency.

## B. BIR `.DAT` writers — no package exists; hand-build
Verified: Packagist/GitHub have **zero** reusable SLSP/alphalist/QAP `.DAT` writers (ecosystem is closed: BIR's own Alphalist/Validation modules, RELIEF, JuanTax). Build as first-class `App\Domain\Bir\Export` writers on `league/csv` + fixed-length emitters, with **golden-file tests** (`spatie/phpunit-snapshot-assertions`) — one approved byte-exact `.dat` fixture per form/version; version the field-order per BIR module release and run each release's output through BIR's own validation module manually (record in the Phase-4 CPA gate).

## C. Money — own value object; **no** money package
The spec's rounding is fully defined in pure integer math (`intdiv(base*rate_bp + 5000, 10000)`, largest-remainder). Ship a readonly `Money` class wrapping `int` centavos + an Eloquent cast. `brick/money` rejected: still 0.x (breaking minors), drags in arbitrary-precision machinery unneeded for single-currency BIGINT centavos, and its API invites math outside `RoundingPolicy`. Revisit only if multi-currency lands.

## D. Composer — dev/CI

| Package | Constraint | Why |
|---|---|---|
| `larastan/larastan` | `^3.10` | PHPStan for Laravel 12 (**not currently installed** — verified); max level on `App\Domain\Ledger`, baseline elsewhere; required CI check |
| `infection/infection` | `^0.31` | Mutation testing **scoped to `Domain\Ledger` only** (rounding/posting/sequences — where an off-by-one-centavo survives normal tests); repo-wide is too slow |
| `spatie/phpunit-snapshot-assertions` | `^5.4` | Golden files for `.dat` exports, books CSV, 2307 renders |

Pint already installed. CI shape (GitHub Actions — none exists yet): pint → larastan → SQLite fast suite → **`mariadb:11.8` service container** for the Ledger suite + `ledger:verify` (the gapless-concurrency property needs real parallel connections — impossible on SQLite) → `npm ci && npm run build` → `composer audit`. Branch protection: Ledger suite + larastan required.

## E. Explicitly NOT adding (YAGNI / rejected)
`maatwebsite/excel` (in-memory workbooks — wrong for huge books), `mpdf` (GPL), `simplesoftwareio/simple-qrcode` (dead since 2021), `brick/money`/`moneyphp` (see C), `eris`/Pest property plugins (05 mandates the in-repo generator), `staudenmeir/laravel-adjacency-list` (L12 cap; CoA trees are tiny — PHP recursion or a MariaDB recursive CTE), Flare (no self-host), `owen-it/laravel-auditing`/`spatie/laravel-activitylog` (mutable, non-chained — would fight the custom `audit_log`), any accounting package as a dependency (D4), bank-feed SDKs, FX libs, EIS clients (build the emitter interface only when the schema is confirmed).

## F. Frontend — zero required npm additions; fix `package.json` first
PrimeVue 4 already covers: money input (`InputNumber` `mode="currency" currency="PHP"` — wrap in a thin component converting to/from **centavos** at the Inertia boundary), TIN mask (`InputMask` `mask="999-999-999-99999"`), charts (`Chart` wraps installed chart.js), DataTable/Dialog via the existing CRUD components. **Custom builds:** BIR invoice/books print CSS (`@page` + mandatory header/footer partial), amount-in-words display, the journal-entry line editor (debit/credit XOR grid with running balance), the receiving grid (UoM cascade + live conversion preview per `08`), count entry (barcode-jump, blind mode).

**`package.json` is currently broken — fix in Phase 0:** `@inertiajs/vue3` is in `dependencies` (`^1.3.0`) *and* `devDependencies` (`^2.0.0`) — keep **v2** only (matches server `inertia-laravel ^2`); same duplication for `vue` and `@vitejs/plugin-vue` (v5 vs v6); `tailwindcss ^3.2` vs `@tailwindcss/vite ^4.0` is a major-version mismatch — pick one.

## G. Amount-in-words — `ext-intl` suffices
`NumberFormatter('en', SPELLOUT)` + a tiny helper for the PH convention ("… PESOS AND xx/100 ONLY"). No package. Snapshot-test the helper; treat ICU version bumps as a review point.

## H. Platform decision — raise PHP floor to `^8.3` (recommended, Phase 0)
The ecosystem floor is moving (openspout 5.x, endroid 6.1, infection 0.34 all want ≥8.3/8.4); the dev box already runs PHP 8.3. Raise `composer.json` to `^8.3` + set `config.platform.php` to the VPS version **before** locking Phase-0 dependencies — verify the target VPS first (recorded as D13 in `06`).
