# 11 — Frontend: Sakai Design System & Reusable Component Library

The UI standard is **Sakai PrimeVue** (the vendored template under `resources/js/layout/` + `assets/styles.scss`) on **PrimeVue 4 / Aura preset / Tailwind**, dark mode via `.app-dark`. Reference: [sakai.primevue.org](https://sakai.primevue.org/) (source: `primefaces/sakai-vue` — layout components `AppLayout/AppTopbar/AppSidebar/AppMenu/AppMenuItem/AppFooter/AppConfigurator` + `layout/composables/layout.js`; 15 UIKit pattern pages: FormLayout, Input, Button, Table, List, Tree, Panels, Overlay, Media, Menu, Messages, File, Chart, Timeline, Misc; `views/pages/Crud.vue` is the canonical CRUD page; dashboards are built from `components/dashboard/*Widget.vue`).

Audit date: 2026-07-21. Ground truth from a full `resources/js` audit.

## 1. Current state (audited)

**Working:** the live shell is [`Layouts/AuthenticatedLayout.vue`](../../resources/js/Layouts/AuthenticatedLayout.vue) — correctly rewritten into a Sakai shell (AppTopbar/AppSidebar/AppFooter, `layout-wrapper` classes, `useLayout()`, AppBreadcrumb, `<Toast>`). Theme setup is correct (`Aura` preset, `darkModeSelector: '.app-dark'`, AppConfigurator preset/palette switching). `Auth/Login.vue` and `Dashboard.vue` are Sakai-styled (with demo data / "PrimeLand" placeholder copy to replace).

**Page verdicts:**
| Page | State |
|---|---|
| Dashboard | Sakai ✔ (demo data) |
| Users Index/Create/Edit | Mixed — uses CRUD components but full of **PrimeFlex/v3 legacy classes**; inline delete Dialog; hardcoded avatar hex palette |
| Tenants Index/Create | **Not Sakai** — hand-rolled Tailwind cards, hardcoded badge colors, native `confirm()` |
| Settings | **Not Sakai** — raw Tailwind, native `<input type="color">`, hardcoded hex |
| Profile (3 partials) | **Pure Breeze** — `TextInput/InputLabel/PrimaryButton/Modal`, dead `#header` slot |
| Notifications | **Not Sakai** — hand-rolled, hardcoded color maps, sample data |
| Auth Register/Forgot/Reset/Confirm/Verify | **Breeze** (inconsistent with the Sakai Login) |

**Structural debt:** two component roots (`components/` Sakai-custom vs `Components/` Breeze leftovers — duplicate Modal/buttons/inputs/dropdown concepts); orphan `layout/AppLayout.vue` (router-view based, unused — delete); `AuthenticatedLayout` lacks a `#header`/actions slot; `usePageTitle.js` fights Inertia's `<Head>` with MutationObserver+setTimeout (replace with Inertia `<Head>` convention); `ConfirmationService` + `ToastService` registered but **no `ConfirmDialog` mounted and zero `useConfirm()`/`useToast()` calls** — three different delete-confirm patterns coexist; v3 component names still imported (`Dropdown`→v4 `Select`, `Calendar`→v4 `DatePicker`); no `InputMask`/`InputNumber`/`Skeleton` anywhere.

## 2. Conventions (the rules every new page follows)

1. **PrimeVue 4 names only** — `Select` not `Dropdown`, `DatePicker` not `Calendar`, `Drawer` not `Sidebar`, `Popover` not `OverlayPanel`. Purge PrimeFlex/v3 classes (`p-button-*` color classes, `p-fluid`, `text-900/600/500`, `align-items-*`, `flex-column`, `grid/col-12`) → Tailwind utilities + component props (`severity`, `text`, `outlined`, `rounded`).
2. **Theme tokens, never hardcoded colors** — no raw hex, no `bg-green-100 text-green-800` badges; use `Tag`/`severity`, surface tokens (`text-surface-500 dark:text-surface-400`, `text-muted-color`, `bg-surface-*`), `var(--p-primary-color)` where CSS is needed. Dark mode must come free via tokens, never `isDark ?` ternaries.
3. **`.card` is the container** — every content block sits in a Sakai `.card` (not hand-rolled `bg-white dark:bg-gray-800 shadow` divs).
4. **One confirm, one toast** — mount `<ConfirmDialog>` once in `AuthenticatedLayout`; all destructive actions use `useConfirm()`; all mutation results fire `useToast()` (success from Inertia `onSuccess`/flash, errors from `onError`). Native `confirm()` and one-off Dialogs are banned.
5. **Money crosses the wire as centavos** (integers); `MoneyInput`/`MoneyText` convert at the boundary. TINs are masked. Dates use `DatePicker` with the Manila-date convention (D18).
6. **Single component root:** everything lives in `resources/js/components/` (lowercase). The Breeze `Components/` directory is **retired** — its consumers (Profile, auth pages) are converted, then it's deleted.
7. Pages receive props from Inertia and stay thin — logic in composables (`useLayout`, `useCrud` (new), `useMoney` (new)).

## 3. The reusable component library (build list)

Legend: 🔧 = evolve existing file · ✨ = new. Each is a thin, prop-driven wrapper over PrimeVue with Sakai styling baked in.

### Shell & navigation
| Component | Base | Notes |
|---|---|---|
| ✨ `AppPageHeader` | — | Title, subtitle, `#actions` slot (buttons), optional breadcrumb override. Replaces every hand-rolled `h1+p`. Add a real `#header` slot to `AuthenticatedLayout` (fixes Profile's dead slot). |
| 🔧 `AppBreadcrumb` | Breadcrumb | Exists — keep; auto-derive from route with page override. |
| 🔧 `UserMenu` | Menu + Avatar | **Rebuild** hand-rolled `UserDropdown` on PrimeVue `Menu` (popup) + `Avatar` (token-based label colors, drop the 20-hex palette). |
| 🔧 `NotificationMenu` | Popover + Badge | **Rebuild** hand-rolled `NotificationDropdown`; severity-token type styling; real data wiring. |
| ✨ `PageTitle` | Inertia `<Head>` | Replace the MutationObserver `usePageTitle` with plain `<Head :title>` + " - Soro SaaS" suffix. |

### Data display
| Component | Base | Notes |
|---|---|---|
| 🔧 `AppDataTable` | DataTable + Toolbar | Evolve `CRUDDataTable`: purge v3 classes; expose `v-model:selection`; **server-side mode** (lazy pagination/sort/filter via Inertia partial reloads — client-only filtering won't survive real ledger volumes); `#toolbar-actions`, `#columns`, `#empty` slots; skeleton rows while loading; sticky money-column alignment (right, tabular-nums). |
| ✨ `StatCard` | .card | Prop-driven (label, value, icon, delta, severity) — replaces the hardcoded 4-up `StatsWidget`; dashboard becomes a grid of `StatCard` + chart widgets per the Sakai widget idiom. |
| ✨ `StatusTag` | Tag | Central severity map for domain statuses (`draft/posted/void`, `active/inactive`, `open/closed/locked`, count/document states). Kills all hardcoded badge colors. |
| ✨ `EmptyState` | — | Icon + title + hint + optional action button; used by tables, Notifications, first-run screens (onboarding phase). |
| ✨ `MoneyText` | — | Formats centavos → `₱1,234.56` (tabular-nums, negative in red-severity, optional sign). Single source of peso formatting. |
| ✨ `AppSkeleton` patterns | Skeleton | Table-row, card, and form skeletons (Skeleton is currently unused anywhere). |
| ✨ `Timeline` usage pattern | Timeline | For document activity/audit trails (invoice history, JE correction chains). |

### Forms
| Component | Base | Notes |
|---|---|---|
| 🔧 `AppForm` | — | Evolve `CRUDForm`: purge v3 classes; `#fields`, `#footer-start` slots; wire `form.processing`; sticky footer on long forms. |
| 🔧 `AppField` | dynamic | Evolve `CRUDField`: **rename v3 imports** (`Select`, `DatePicker`); add `InputNumber`, `InputMask`, `ToggleSwitch`, `RadioButton` to the component map; error/help/required built-in; 12-col span prop. |
| ✨ `FormSection` | — | Titled group with description + field grid (the Sakai FormLayout pattern); replaces ad-hoc `space-y-6`. |
| ✨ `MoneyInput` | InputNumber | `mode="currency" currency="PHP"`, min/max, **v-model in centavos** (converts internally). |
| ✨ `TinInput` | InputMask | `999-999-999-99999` (TIN + branch code), validation hint. |
| ✨ `DateInput` | DatePicker | Manila-date convention, period-lock aware (`:min-date` from open period) variant for entry dates. |
| ✨ `RateInput` | InputNumber | Percent display, v-model in **basis points**. |
| ✨ `SelectField` extras | Select/MultiSelect/TreeSelect | Standard filter/clear config; virtual scroll for big lists. |
| ✨ `FileUploadField` | FileUpload | Wraps upload + preview + medialibrary wiring (receipts/attachments); replaces the raw Settings usage. |
| ✨ `ColorField` | ColorPicker | Replaces Settings' native `<input type="color">`. |
| ✨ `PasswordField` | Password | Meter + policy hints (04's rotation policy). |

### Overlays & feedback
| Component | Base | Notes |
|---|---|---|
| 🔧 `AppModal` | Dialog | Consolidate `CRUDModal` (keep) + retire Breeze `Modal`; drop the global registration in favor of explicit imports. |
| ✨ Confirm pattern | ConfirmDialog | Mount once in layout; `useDeleteConfirm(label)` composable so every destructive action reads identically. |
| ✨ Toast pattern | Toast | `useAppToast()` composable (success/error/info helpers) + flash-message bridge from Laravel redirects. |
| ✨ `Drawer` usage pattern | Drawer | For quick-view (document preview, audit detail) without leaving lists. |

### Accounting-specific (Phase 1–2b, built on the kit above)
| Component | Notes |
|---|---|
| ✨ `JournalEntryGrid` | The debit/credit line editor: account picker per line, debit XOR credit enforcement in-cell, running Σdebit/Σcredit + out-of-balance indicator, add/remove lines, keyboard-first (Enter = next line). |
| ✨ `AccountPicker` | `TreeSelect`/`Select` over the CoA — postable leaves only, code+name display, recent-accounts group. |
| ✨ `PartnerPicker` | Customer/vendor autocomplete with TIN display + inline-create. |
| ✨ `TaxBreakdownCard` | VATable/exempt/zero-rated + VAT + EWT summary block for document forms (mirrors the invoice mandatory-field breakdown). |
| ✨ `DocumentStatusTag` | `StatusTag` preset for `draft/posted/void/cancelled` + reversal linkage hint. |
| ✨ `AmountInWords` | Renders the intl SPELLOUT helper ("… PESOS AND xx/100 ONLY") on invoice templates. |
| ✨ `PeriodSelector` | Fiscal period dropdown with open/closed/locked states (locked disabled). |
| ✨ `AgingTable` | Bucketed A/R–A/P aging (current/30/60/90+) from the subledger. |
| ✨ `ReceivingGrid` | Spec `08` §5: item picker + barcode scan-to-add, UoM cascade with live "`{qty} {purchase} = {base} {stock}`" preview, lot/expiry sub-row. |
| ✨ `CountGrid` | Spec `08` §4: counted-qty entry, blind mode, variance column valued at cost, barcode-jump. |
| ✨ `PrintFrame` | Renders the BIR print artifacts (invoice/books) with the mandatory header/footer partial + print CSS (`@page`). |

## 4. Conversion backlog (make the existing app Sakai-consistent)

Ordered; each item is small and independent. Do the kit pieces a page needs, then convert the page.

1. **Layout fixes:** add `#header` slot to `AuthenticatedLayout`; delete orphan `layout/AppLayout.vue`; replace `usePageTitle` with Inertia `<Head>`; mount `<ConfirmDialog>`.
2. **Users** (closest to done): purge PrimeFlex classes from the 4 CRUD components + 3 pages; swap inline delete Dialog → confirm pattern; avatar palette → `Avatar` token styling; implement or remove the `exportUsers` stub.
3. **Profile:** rebuild the 3 partials on `FormSection`/`AppField`/PrimeVue Buttons inside `.card`; delete-account uses the confirm pattern. Retires most Breeze components.
4. **Settings:** rebuild on `FormSection`/`AppField`/`ColorField`/`FileUploadField`; use `form.processing` + toast.
5. **Tenants:** convert Index to `AppDataTable` + `StatusTag`; Create to `AppForm`/`AppField`; confirm pattern for delete.
6. **Notifications:** rebuild on `.card` + severity tokens + `EmptyState`; wire real notification data (also fixes the hardcoded samples in `AppTopbar`).
7. **Auth:** port Register/Forgot/Reset/Confirm/Verify onto the Sakai Login template style; replace "PrimeLand" copy + inline border-radius.
8. **Delete `resources/js/Components/`** once nothing imports it; single lowercase root remains.
9. `tailwind.config.js` / v3-vs-v4 Tailwind mismatch: resolve alongside the `package.json` fixes (spec `09` §F).

**Phase placement:** items 1–2 + the form/table kit land in **Phase 0** (they're prerequisites for every accounting screen); conversions 3–7 proceed alongside Phase 1 as warm-up tickets; accounting-specific components arrive with their features (Phase 1–2b). *Exit criterion (add to Phase 0): no page imports from `Components/` (uppercase), no PrimeFlex classes, `ConfirmDialog`+Toast wired, and Users/Profile/Settings render in the Sakai pattern in both light and `.app-dark` modes.*
