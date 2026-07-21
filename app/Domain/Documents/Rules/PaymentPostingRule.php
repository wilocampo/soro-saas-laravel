<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PartyRef;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Collection / disbursement → journal (docs/specs/02 §4.2, fixture S4).
 *
 * Accrual — the cash simply settles the receivable/payable:
 *   received: Dr Cash / Dr Creditable Withholding Tax / Cr A/R
 *   paid:     Dr A/P / Cr Cash / Cr Withholding Tax Payable
 *
 * Cash basis — recognition happens HERE: the rule walks the payment's
 * allocations and re-derives each document's revenue/expense and VAT from
 * its stored snapshot, pro-rated for partials by largest remainder (§3).
 * That snapshot is why a cash-basis invoice records tax_base even though
 * it posts nothing.
 */
class PaymentPostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'payment';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var Payment $payment */
        $payment = $document;

        $isReceipt = $payment->direction === 'received';
        $entryDate = CarbonImmutable::parse($payment->payment_date->toDateString());
        $party = new PartyRef($isReceipt ? 'customer' : 'vendor', (int) $payment->partner_id);

        $lines = $isReceipt
            ? $this->cashSide($payment, $context, $party)
            : $this->disbursementCashSide($payment, $context, $party);

        $lines = array_merge($lines, $context->basis->isCash()
            ? $this->recognitionLines($payment, $context, $party, $isReceipt)
            : $this->controlAccountLines($payment, $context, $party, $isReceipt));

        // Whatever the cash did not settle is an advance, in either basis.
        $lines = array_merge($lines, $this->depositLines($payment, $context, $party, $isReceipt));

        return new JournalDraft(
            journalBook: $isReceipt ? 'cash_receipts' : 'cash_disbursements',
            entryDate: $entryDate,
            memo: $payment->memo ?? ($isReceipt ? 'Collection' : 'Disbursement'),
            source: new SourceRef('payment', (int) $payment->id),
            idempotencyKey: "payment:{$payment->id}:post:1",
            lines: $lines,
        );
    }

    /**
     * Money in: cash + the tax the customer withheld from us (2307 asset).
     *
     * @return list<JournalLineDraft>
     */
    private function cashSide(Payment $payment, PostingContext $context, PartyRef $party): array
    {
        $lines = [new JournalLineDraft(
            accountId: (int) $payment->cash_account_id,
            debitCentavos: $payment->amount_centavos,
            memo: 'Cash received',
            party: $party,
        )];

        if ($payment->ewt_centavos > 0) {
            // Creditable Withholding Tax — an ASSET claimed via Form 2307,
            // the mirror of the withholding LIABILITY on the vendor side.
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('cwt_asset'),
                debitCentavos: $payment->ewt_centavos,
                memo: 'Creditable withholding tax (2307)',
                taxBaseCentavos: $this->taxBase($payment),
                atcCode: $payment->atc_code,
                party: $party,
            );
        }

        return $lines;
    }

    /**
     * Money out: cash paid, plus any tax withheld from the vendor.
     *
     * @return list<JournalLineDraft>
     */
    private function disbursementCashSide(Payment $payment, PostingContext $context, PartyRef $party): array
    {
        $lines = [new JournalLineDraft(
            accountId: (int) $payment->cash_account_id,
            creditCentavos: $payment->amount_centavos,
            memo: 'Cash paid',
            party: $party,
        )];

        if ($payment->ewt_centavos > 0) {
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('wht_payable'),
                creditCentavos: $payment->ewt_centavos,
                memo: 'Withholding tax payable',
                taxBaseCentavos: $this->taxBase($payment),
                atcCode: $payment->atc_code,
                party: $party,
            );
        }

        return $lines;
    }

    /**
     * Accrual: the settlement simply clears the control account.
     *
     * @return list<JournalLineDraft>
     */
    private function controlAccountLines(Payment $payment, PostingContext $context, PartyRef $party, bool $isReceipt): array
    {
        // Only the APPLIED portion clears the control account. Crediting the
        // whole receipt would drive A/R negative for the customer, hiding an
        // advance inside the receivable balance.
        $applied = $payment->settledCentavos() - $payment->unappliedCentavos();

        if ($applied === 0) {
            return [];
        }

        return [new JournalLineDraft(
            accountId: $context->accounts->id($isReceipt ? 'ar' : 'ap'),
            debitCentavos: $isReceipt ? 0 : $applied,
            creditCentavos: $isReceipt ? $applied : 0,
            memo: $isReceipt ? 'Applied to receivable' : 'Applied to payable',
            party: $party,
        )];
    }

    /**
     * Unapplied cash is not income — it is money held against future
     * performance: a LIABILITY when a customer pays us in advance, an ASSET
     * when we advance a supplier. Recognition waits for the document.
     *
     * @return list<JournalLineDraft>
     */
    private function depositLines(Payment $payment, PostingContext $context, PartyRef $party, bool $isReceipt): array
    {
        $unapplied = $payment->unappliedCentavos();

        if ($unapplied === 0) {
            return [];
        }

        if ($unapplied < 0) {
            throw new InvalidDraft(
                "Payment allocations exceed the payment by {$unapplied} centavos — a document cannot be over-applied."
            );
        }

        return [new JournalLineDraft(
            accountId: $context->accounts->id($isReceipt ? 'customer_deposit' : 'vendor_advance'),
            debitCentavos: $isReceipt ? 0 : $unapplied,
            creditCentavos: $isReceipt ? $unapplied : 0,
            memo: $isReceipt ? 'Customer deposit (unapplied)' : 'Advance to supplier (unapplied)',
            party: $party,
        )];
    }

    /**
     * Cash basis: expand each allocated document's snapshot. The applied
     * amount is split across the document's components (line nets + tax)
     * by largest remainder, so the parts sum to the cash exactly.
     *
     * @return list<JournalLineDraft>
     */
    private function recognitionLines(Payment $payment, PostingContext $context, PartyRef $party, bool $isReceipt): array
    {
        // Unapplied cash is handled by depositLines(); here we only expand
        // what the payment actually settled.
        $lines = [];

        foreach ($payment->allocations as $allocation) {
            $components = $this->componentsFor($allocation->allocatable_type, (int) $allocation->allocatable_id, $context);

            if ($components === []) {
                throw new InvalidDraft("Allocated document [{$allocation->allocatable_type}:{$allocation->allocatable_id}] has no recognizable components.");
            }

            $weights = array_map(fn (array $c) => $c['amount'], $components);
            $parts = $context->rounding->allocate((int) $allocation->applied_centavos, $weights);

            foreach ($components as $index => $component) {
                if ($parts[$index] === 0) {
                    continue;
                }

                $lines[] = new JournalLineDraft(
                    accountId: $component['account_id'],
                    debitCentavos: $isReceipt ? 0 : $parts[$index],
                    creditCentavos: $isReceipt ? $parts[$index] : 0,
                    memo: $component['memo'],
                    taxCodeId: $component['tax_code_id'],
                    taxBaseCentavos: $component['is_tax'] ? null : $parts[$index],
                    party: $party,
                    sourceLineRef: $allocation->allocatable_type.':'.$allocation->allocatable_id,
                );
            }
        }

        return $lines;
    }

    /**
     * The recognizable pieces of a document: each line's net (to its own
     * revenue/expense account) plus the VAT component.
     *
     * @return list<array{account_id:int, amount:int, memo:string, tax_code_id:?int, is_tax:bool}>
     */
    private function componentsFor(string $type, int $id, PostingContext $context): array
    {
        $components = [];

        if ($type === 'sales_invoice') {
            $invoice = SalesInvoice::with('lines')->findOrFail($id);

            foreach ($invoice->lines as $line) {
                if ((int) $line->net_centavos > 0) {
                    $components[] = [
                        'account_id' => (int) $line->account_id,
                        'amount' => (int) $line->net_centavos,
                        'memo' => $line->description,
                        'tax_code_id' => $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                        'is_tax' => false,
                    ];
                }
            }

            if ($invoice->vat_centavos > 0) {
                $components[] = [
                    'account_id' => $context->accounts->id('output_vat'),
                    'amount' => $invoice->vat_centavos,
                    'memo' => 'Output VAT',
                    'tax_code_id' => null,
                    'is_tax' => true,
                ];
            }

            return $components;
        }

        if ($type === 'vendor_bill') {
            $bill = VendorBill::with('lines')->findOrFail($id);

            foreach ($bill->lines as $line) {
                if ((int) $line->net_centavos > 0) {
                    $components[] = [
                        'account_id' => (int) $line->account_id,
                        'amount' => (int) $line->net_centavos,
                        'memo' => $line->description,
                        'tax_code_id' => $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                        'is_tax' => false,
                    ];
                }
            }

            if ($bill->input_vat_centavos > 0) {
                $components[] = [
                    'account_id' => $context->accounts->id('input_vat'),
                    'amount' => $bill->input_vat_centavos,
                    'memo' => 'Input VAT',
                    'tax_code_id' => null,
                    'is_tax' => true,
                ];
            }

            return $components;
        }

        throw new InvalidDraft("Unknown allocatable document type [{$type}].");
    }

    /** The withholding base is the net of the documents settled. */
    private function taxBase(Payment $payment): ?int
    {
        $base = 0;

        foreach ($payment->allocations as $allocation) {
            $base += match ($allocation->allocatable_type) {
                'sales_invoice' => (int) SalesInvoice::whereKey($allocation->allocatable_id)->value('net_centavos'),
                'vendor_bill' => (int) VendorBill::whereKey($allocation->allocatable_id)->value('net_centavos'),
                default => 0,
            };
        }

        return $base > 0 ? $base : null;
    }
}
