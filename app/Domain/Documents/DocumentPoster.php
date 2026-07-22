<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\CreditNote;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Rules\CreditNotePostingRule;
use App\Domain\Documents\Rules\PaymentPostingRule;
use App\Domain\Documents\Rules\SalesInvoicePostingRule;
use App\Domain\Documents\Rules\VendorBillPostingRule;
use App\Domain\Inventory\InventoryEffects;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Rules\GoodsReceiptPostingRule;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Posting\AccountResolver;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\RoundingPolicy;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\TaxResolver;
use App\Domain\Ledger\PostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The uniform document workflow (docs/specs/02 §2):
 *
 *   $draft = $rules->for($doc)->build($doc, $ctx);
 *   $entry = $posting->post($draft);     // null for cash-basis no-ops
 *   $doc->linkJournalEntry($entry?->id);
 *
 * Documents never touch PostingService directly — the rule owns the
 * accounting treatment, this owns the plumbing.
 */
class DocumentPoster
{
    /** @var array<class-string<Model>, class-string<PostingRule>> */
    private const RULES = [
        SalesInvoice::class => SalesInvoicePostingRule::class,
        VendorBill::class => VendorBillPostingRule::class,
        Payment::class => PaymentPostingRule::class,
        CreditNote::class => CreditNotePostingRule::class,
        GoodsReceipt::class => GoodsReceiptPostingRule::class,
    ];

    /** Document class → [column holding the serial, continuous series code]. */
    private const SERIALS = [
        SalesInvoice::class => ['invoice_number', 'INV'],
        VendorBill::class => ['reference', 'BILL'],
        CreditNote::class => ['note_number', null],   // CM or DM — see serialSeries()
        Payment::class => ['payment_number', null],   // RC or CV
        GoodsReceipt::class => ['reference', 'GR'],
    ];

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountResolver $accounts,
        private readonly TaxResolver $tax,
        private readonly RoundingPolicy $rounding,
        private readonly DocumentSerialService $serials,
        private readonly DocumentBalances $balances,
        private readonly InventoryEffects $inventory,
    ) {}

    public function post(Model $document): ?JournalEntry
    {
        $ruleClass = self::RULES[$document::class] ?? null;

        if ($ruleClass === null) {
            throw new InvalidDraft('No posting rule is registered for ['.$document::class.'].');
        }

        /** @var PostingRule $rule */
        $rule = app($ruleClass);

        // One transaction covers the serial draw AND the journal post, so a
        // failed post never burns a customer-facing number (CLAUDE.md #6).
        return DB::transaction(function () use ($document, $rule): ?JournalEntry {
            $this->assignSerial($document);

            $entry = $this->posting->post($rule->build($document, $this->context()));

            // Subledger ↔ journal link (nullable: a cash-basis invoice has none).
            if ($document->getAttribute('journal_entry_id') !== ($entry?->id)) {
                DB::table($document->getTable())->where('id', $document->getKey())
                    ->update(['journal_entry_id' => $entry?->id]);
                $document->setAttribute('journal_entry_id', $entry?->id);
            }

            // Quantity moves in the SAME transaction as the money, so the
            // journal and the stock ledger can never disagree (08 §3).
            $this->inventory->apply($document, $entry);

            $this->settle($document);

            return $entry;
        });
    }

    /**
     * A payment or note changes what its documents still owe. The cached
     * columns are recomputed from the allocations, never incremented — so a
     * re-post or a partial replay can never drift (CLAUDE.md #2).
     */
    private function settle(Model $document): void
    {
        if ($document instanceof Payment) {
            $this->balances->refreshForPayment($document->load('allocations'));
        }

        if ($document instanceof CreditNote) {
            // Read the status from the row, not the model: a just-created
            // model carries no attribute for a column filled by a DB default.
            $status = DB::table('credit_notes')->where('id', $document->getKey())->value('status');

            if ($status === 'draft') {
                DB::table('credit_notes')->where('id', $document->getKey())
                    ->update(['status' => 'issued', 'issued_at' => now()]);
                $document->setAttribute('status', 'issued');
            }
            $this->balances->refreshForNote($document);
        }
    }

    /**
     * Issue the document's serial if it has none. Idempotent by construction:
     * a document that already carries a number keeps it, so re-posting never
     * draws a second serial for the same document.
     */
    private function assignSerial(Model $document): void
    {
        [$column] = self::SERIALS[$document::class] ?? [null];

        if ($column === null || $document->getAttribute($column) !== null) {
            return;
        }

        $number = $this->serials->next($this->serialSeries($document));

        DB::table($document->getTable())->where('id', $document->getKey())->update([$column => $number]);
        $document->setAttribute($column, $number);
    }

    private function serialSeries(Model $document): string
    {
        return match (true) {
            $document instanceof CreditNote => $document->isCredit() ? 'CM' : 'DM',
            $document instanceof Payment => $document->direction === 'received' ? 'RC' : 'CV',
            default => self::SERIALS[$document::class][1],
        };
    }

    /** Pure preview for the UI — runs the rule, writes nothing. */
    public function preview(Model $document): JournalDraft
    {
        $ruleClass = self::RULES[$document::class] ?? null;

        if ($ruleClass === null) {
            throw new InvalidDraft('No posting rule is registered for ['.$document::class.'].');
        }

        /** @var PostingRule $rule */
        $rule = app($ruleClass);

        return $this->posting->preview($rule->build($document, $this->context()));
    }

    private function context(): PostingContext
    {
        // Read per call: the basis is a tenant setting, and tests/tenants
        // switch connections underneath a long-lived container binding.
        $this->accounts->forget();

        return PostingContext::fromSettings($this->accounts, $this->tax, $this->rounding);
    }
}
