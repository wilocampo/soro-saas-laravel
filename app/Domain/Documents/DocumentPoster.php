<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Rules\PaymentPostingRule;
use App\Domain\Documents\Rules\SalesInvoicePostingRule;
use App\Domain\Documents\Rules\VendorBillPostingRule;
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
    ];

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountResolver $accounts,
        private readonly TaxResolver $tax,
        private readonly RoundingPolicy $rounding,
    ) {}

    public function post(Model $document): ?JournalEntry
    {
        $ruleClass = self::RULES[$document::class] ?? null;

        if ($ruleClass === null) {
            throw new InvalidDraft('No posting rule is registered for ['.$document::class.'].');
        }

        /** @var PostingRule $rule */
        $rule = app($ruleClass);

        $draft = $rule->build($document, $this->context());
        $entry = $this->posting->post($draft);

        // Subledger ↔ journal link (nullable: a cash-basis invoice has none).
        if ($document->getAttribute('journal_entry_id') !== ($entry?->id)) {
            DB::table($document->getTable())->where('id', $document->getKey())
                ->update(['journal_entry_id' => $entry?->id]);
            $document->setAttribute('journal_entry_id', $entry?->id);
        }

        return $entry;
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
