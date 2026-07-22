<?php

use App\Domain\Reports\DashboardSummary;
use App\Http\Controllers\AgingController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorBillController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    // Dashboard-lite reads the tenant ledger, which only exists on a tenant
    // connection — the landlord dashboard stays empty rather than querying
    // tables that are not there.
    return Inertia::render('Dashboard', [
        'summary' => currentTenant() === null
            ? null
            : app(DashboardSummary::class)->forToday(),
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/notifications', function (Request $request) {
    // Laravel database notifications; real producers arrive with their
    // features (backup failures, period close, provisioning) — spec 10 §6.
    return Inertia::render('Notifications', [
        'notifications' => $request->user()->notificationSummaries(),
    ]);
})->middleware(['auth', 'verified'])->name('notifications');

Route::post('/notifications/read', function (Request $request) {
    $request->user()->unreadNotifications->markAsRead();

    return back();
})->middleware(['auth', 'verified'])->name('notifications.read');

Route::post('/notifications/{id}/read', function (Request $request, string $id) {
    $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

    return back();
})->middleware(['auth', 'verified'])->name('notifications.read-one');

// Test route to verify routing works
Route::get('/test-users', function () {
    return response()->json(['message' => 'Users route is working', 'tenant' => currentTenant()?->name ?? 'No tenant']);
})->middleware(['auth', 'verified', 'landlord'])->name('test.users');

// Tenant management routes (landlord only)
Route::middleware(['auth', 'verified', 'landlord'])->group(function () {
    Route::resource('tenants', TenantController::class);
    // Users management from landlord perspective
    Route::delete('users-bulk', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
    Route::resource('users', UserController::class);
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// Tenant-specific routes (when accessing from tenant subdomain)
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::resource('tenant-users', UserController::class)->names([
        'index' => 'tenant.users.index',
        'create' => 'tenant.users.create',
        'store' => 'tenant.users.store',
        'show' => 'tenant.users.show',
        'edit' => 'tenant.users.edit',
        'update' => 'tenant.users.update',
        'destroy' => 'tenant.users.destroy',
    ]);
    Route::get('/tenant-settings', [SettingsController::class, 'index'])->name('tenant.settings.index');
    Route::put('/tenant-settings', [SettingsController::class, 'update'])->name('tenant.settings.update');

    // --- documents (Phase 2) ------------------------------------------
    // Ledger tables live in the tenant DB, so every route here is tenant-
    // scoped by construction. No document is ever destroyed: invoices and
    // bills are CANCELLED (CLAUDE.md #4) and partners are deactivated.
    //
    // READ routes are deliberately OUTSIDE the `can-post` gate: a lapsed
    // subscription must never hide a taxpayer's own books, because BIR holds
    // the registrant responsible for producing them (Phase 5).
    Route::get('partners', [PartnerController::class, 'index'])->name('partners.index');
    Route::resource('invoices', SalesInvoiceController::class)->only(['index', 'create', 'show']);
    Route::get('invoices/{invoice}/pdf', [SalesInvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::resource('bills', VendorBillController::class)->only(['index', 'create', 'show'])
        ->parameters(['bills' => 'bill']);
    Route::resource('payments', PaymentController::class)->only(['index', 'create']);
    Route::get('payments/open-documents', [PaymentController::class, 'openDocuments'])->name('payments.open-documents');
    Route::resource('items', ItemController::class)->only(['index']);
    Route::get('items/barcodes/lookup/{barcode}', [ItemController::class, 'lookupBarcode'])->name('items.barcode');
    Route::resource('receipts', GoodsReceiptController::class)->only(['index', 'create', 'show'])
        ->parameters(['receipts' => 'receipt']);
    Route::resource('counts', StockCountController::class)->only(['index', 'show'])
        ->parameters(['counts' => 'count']);

    // WRITE routes: gated on an active subscription.
    Route::middleware('can-post')->group(function () {
        Route::post('partners', [PartnerController::class, 'store'])->name('partners.store');
        Route::put('partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
        Route::delete('partners/{partner}', [PartnerController::class, 'destroy'])->name('partners.destroy');

        Route::post('invoices', [SalesInvoiceController::class, 'store'])->name('invoices.store');
        Route::post('invoices/{invoice}/email', [SalesInvoiceController::class, 'email'])->name('invoices.email');
        Route::post('invoices/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->name('invoices.cancel');

        Route::post('bills', [VendorBillController::class, 'store'])->name('bills.store');
        Route::post('bills/{bill}/receipt', [VendorBillController::class, 'attachReceipt'])->name('bills.receipt');

        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');

        Route::post('items', [ItemController::class, 'store'])->name('items.store');
        Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

        Route::post('receipts', [GoodsReceiptController::class, 'store'])->name('receipts.store');

        Route::post('counts', [StockCountController::class, 'store'])->name('counts.store');
        Route::post('counts/{count}/record', [StockCountController::class, 'record'])->name('counts.record');
        Route::post('counts/{count}/review', [StockCountController::class, 'review'])->name('counts.review');
        Route::post('counts/{count}/approve', [StockCountController::class, 'approve'])->name('counts.approve');

        Route::post('/reconciliations', [BankReconciliationController::class, 'store'])->name('reconciliations.store');
        Route::post('/reconciliations/{reconciliation}/toggle', [BankReconciliationController::class, 'toggle'])->name('reconciliations.toggle');
        Route::post('/reconciliations/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])->name('reconciliations.complete');
    });

    // Onboarding and the go-live gate (Phase 5). Reading it is never gated:
    // a tenant must always be able to see what is outstanding.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('/onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
    Route::post('/onboarding/go-live', [OnboardingController::class, 'goLive'])->name('onboarding.go-live');

    // Billing (Phase 5). Reading the page is never gated — a tenant must be
    // able to see WHY posting stopped, and fix it.
    Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::get('/reports/aging', [AgingController::class, 'index'])->name('reports.aging');

    // Financial reports (Phase 3). Each renders on screen, or exports to
    // pdf/xlsx/csv via ?format= from the SAME description — so an export can
    // never drift from what the operator reviewed on screen.
    Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
        Route::get('/trial-balance', 'trialBalance')->name('trial-balance');
        Route::get('/balance-sheet', 'balanceSheet')->name('balance-sheet');
        Route::get('/income-statement', 'incomeStatement')->name('income-statement');
        Route::get('/general-ledger', 'generalLedger')->name('general-ledger');
        Route::get('/journal', 'journal')->name('journal');
        Route::get('/statement-of-account', 'statementOfAccount')->name('soa');
    });

    // Manual bank reconciliation. There is no adjustment field anywhere in
    // this flow: a difference is an unbooked bank item, and the answer is to
    // post it rather than plug the reconciliation.
    Route::get('/reconciliations', [BankReconciliationController::class, 'index'])->name('reconciliations.index');
    Route::get('/reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])->name('reconciliations.show');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
