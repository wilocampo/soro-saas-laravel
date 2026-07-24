<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Documents\Models\Partner;
use App\Domain\Ledger\TrialBalanceService;
use App\Domain\Reports\Export\ReportRenderer;
use App\Domain\Reports\Export\ReportSheet;
use App\Domain\Reports\Export\ReportSheetFactory;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\GeneralLedgerReport;
use App\Domain\Reports\JournalReport;
use App\Domain\Reports\StatementOfAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The financial reports (Phase 3). Each one renders on screen and exports to
 * PDF, XLSX or CSV from the SAME description, so an export can never drift
 * from what the operator reviewed.
 */
class ReportController extends Controller
{
    private const FORMATS = ['pdf', 'xlsx', 'csv'];

    public function trialBalance(Request $request): Response|BinaryFileResponse
    {
        $periodId = $this->periodId($request);

        return $this->respond($request, 'Reports/TrialBalance', 'trial-balance', [
            'report' => app(TrialBalanceService::class)->asOfPeriod($periodId),
            'periods' => $this->periods(),
            'periodId' => $periodId,
        ], fn () => app(ReportSheetFactory::class)->trialBalance($periodId));
    }

    public function balanceSheet(Request $request): Response|BinaryFileResponse
    {
        $periodId = $this->periodId($request);

        return $this->respond($request, 'Reports/BalanceSheet', 'balance-sheet', [
            'report' => app(FinancialStatements::class)->balanceSheet($periodId),
            'tie' => app(FinancialStatements::class)->tieOut($periodId),
            'periods' => $this->periods(),
            'periodId' => $periodId,
        ], fn () => app(ReportSheetFactory::class)->balanceSheet($periodId));
    }

    public function incomeStatement(Request $request): Response|BinaryFileResponse
    {
        $periodId = $this->periodId($request);
        $yearToDate = $request->boolean('ytd', true);

        return $this->respond($request, 'Reports/IncomeStatement', 'income-statement', [
            'report' => app(FinancialStatements::class)->incomeStatement($periodId, $yearToDate),
            'periods' => $this->periods(),
            'periodId' => $periodId,
            'yearToDate' => $yearToDate,
        ], fn () => app(ReportSheetFactory::class)->incomeStatement($periodId, $yearToDate));
    }

    public function generalLedger(Request $request): Response|BinaryFileResponse
    {
        $accounts = DB::table('accounts')->orderBy('code')->get(['id', 'code', 'name']);
        $accountId = (int) ($request->integer('account_id') ?: ($accounts->first()->id ?? 0));
        [$from, $to] = $this->range($request);

        return $this->respond($request, 'Reports/GeneralLedger', 'general-ledger', [
            'report' => app(GeneralLedgerReport::class)->forAccount($accountId, $from, $to),
            'accounts' => $accounts,
            'accountId' => $accountId,
            'from' => $from,
            'to' => $to,
        ], fn () => app(ReportSheetFactory::class)->generalLedger($accountId, $from, $to));
    }

    public function journal(Request $request): Response|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $book = $request->string('book')->toString() ?: null;

        if ($book !== null && ! array_key_exists($book, JournalReport::BOOKS)) {
            abort(404, "Unknown journal book [{$book}].");
        }

        return $this->respond($request, 'Reports/Journal', 'journal', [
            'report' => app(JournalReport::class)->entries($from, $to, $book),
            'books' => JournalReport::BOOKS,
            'book' => $book,
            'from' => $from,
            'to' => $to,
        ], fn () => app(ReportSheetFactory::class)->journal($from, $to, $book));
    }

    /** Customer statement of account (a SUPPLEMENTARY document — spec 03 §4). */
    public function statementOfAccount(Request $request): Response
    {
        $customers = Partner::query()
            ->where('is_customer', true)->orderBy('registered_name')
            ->get(['id', 'code', 'registered_name']);

        $partnerId = (int) ($request->integer('partner_id') ?: ($customers->first()->id ?? 0));
        [$from, $to] = $this->range($request);

        // A statement is per customer, so with none on file there is nothing
        // to render. This used to abort 404 — fine when the only way here was
        // a hand-typed URL, wrong now that it is a permanent menu item: a new
        // tenant clicking it would meet an error page rather than an empty one.
        return Inertia::render('Reports/StatementOfAccount', [
            'statement' => $partnerId === 0
                ? null
                : app(StatementOfAccount::class)->forCustomer($partnerId, $from, $to),
            'customers' => $customers,
            'partnerId' => $partnerId,
            'from' => $from,
            'to' => $to,
            'header' => app(ReportHeader::class)->for('Statement of Account'),
        ]);
    }

    /**
     * Screen or file, decided by `?format=`. The sheet is built lazily so a
     * screen render never pays for the export description.
     *
     * @param  array<string, mixed>  $props
     * @param  callable():ReportSheet  $sheet
     */
    private function respond(
        Request $request,
        string $component,
        string $basename,
        array $props,
        callable $sheet,
    ): Response|BinaryFileResponse {
        $format = $request->string('format')->toString();

        if ($format === '') {
            return Inertia::render($component, $props + [
                'header' => app(ReportHeader::class)->for($component),
            ]);
        }

        abort_unless(in_array($format, self::FORMATS, true), 404, "Unsupported format [{$format}].");

        $rendered = app(ReportRenderer::class)->render($sheet(), $format, $basename);

        return response()
            ->download($rendered['path'], $rendered['filename'], ['Content-Type' => $rendered['mime']])
            ->deleteFileAfterSend();
    }

    private function periodId(Request $request): int
    {
        $id = $request->integer('period_id');

        if ($id > 0) {
            return $id;
        }

        return (int) DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', CarbonImmutable::now()->toDateString())
            ->whereDate('end_date', '>=', CarbonImmutable::now()->toDateString())
            ->where('period_no', '<=', 12)
            ->value('id');
    }

    /** @return Collection<int, \stdClass> */
    private function periods()
    {
        return DB::table('fiscal_periods as fp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->orderByDesc('fp.end_date')->orderByDesc('fp.period_no')
            ->get(['fp.id', 'fp.period_no', 'fp.start_date', 'fp.end_date', 'fp.status', 'fy.year_label']);
    }

    /** @return array{0:string, 1:string} */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $now = CarbonImmutable::now();

        return [
            $request->string('from')->toString() ?: $now->startOfYear()->toDateString(),
            $request->string('to')->toString() ?: $now->endOfYear()->toDateString(),
        ];
    }
}
