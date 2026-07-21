<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Exceptions\InvoiceNotCompliant;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\Models\CompanyProfile;
use Illuminate\Support\Facades\DB;

/**
 * The eleven mandatory fields of a valid VAT invoice (NIRC Secs. 113(B) &
 * 237 as amended; RR 7-2024 Sec. 3(B)/Sec. 6) — see docs/specs/03 §4.
 *
 * Two severities, and the difference matters:
 *
 *  FATAL — the five "input-tax-fatal" omissions. If any is missing the BUYER
 *          loses the input-VAT claim entirely, so we refuse to issue rather
 *          than hand a customer a document that will fail their audit:
 *          (a) amount of sales, (b) VAT amount, (c) registered name and TIN
 *          of BOTH buyer and seller, (d) description of goods / nature of
 *          service, (e) date of transaction.
 *
 *  WARNING — everything else. Reported, never blocking.
 *
 * ⚠ Buyer name/TIN is fatal only where the law requires it: a sale of
 * ₱1,000 or more to a VAT-registered buyer. Below that it is a warning.
 */
class InvoiceCompliance
{
    /** Buyer identification becomes mandatory at ₱1,000 (item 11). */
    public const BUYER_TIN_THRESHOLD_CENTAVOS = 100_000;

    /**
     * @param  bool  $requireSerial  false while the invoice is still being
     *                               issued: the serial is drawn by the poster
     *                               INSIDE the posting transaction, so it
     *                               cannot exist yet when the pre-post gate
     *                               runs. See assertIssuable().
     * @return array{fatal: list<string>, warnings: list<string>}
     */
    public function check(SalesInvoice $invoice, bool $requireSerial = true): array
    {
        $fatal = [];
        $warnings = [];

        $seller = CompanyProfile::current();
        $buyer = $invoice->partner;
        $vatRegistered = (bool) DB::table('ledger_settings')->where('id', 1)->value('is_vat_registered');

        // (c) seller identity
        if ($seller->isUnconfigured()) {
            $fatal[] = 'The seller profile still holds the provisioning placeholder — set the registered name, address and TIN.';
        }
        if (trim((string) $seller->registered_address) === '') {
            $warnings[] = "The seller's registered business address is blank (mandatory field 3).";
        }
        if (! $seller->isAccredited()) {
            // The AC is the go-live gate (RMC 5-2021, RMO 9-2021) but it is a
            // registration act, not a per-document defect.
            $warnings[] = 'No Acknowledgement Certificate number (ACCN) is recorded — the system is not yet BIR-registered.';
        }

        // (a) amount of sales
        if ((int) $invoice->total_centavos <= 0) {
            $fatal[] = 'The invoice has no amount (mandatory field 9).';
        }

        // (e) date of transaction — NOT NULL at the DB, so there is nothing
        // to check here; the column itself is the enforcement.

        // (5) serial number, printed prominently
        if ($requireSerial && ($invoice->invoice_number ?? '') === '') {
            $fatal[] = 'The invoice has no serial number (mandatory field 5).';
        }

        // (d) description / nature of service, and (6) quantity + unit cost
        $lines = $invoice->lines;
        if ($lines->isEmpty()) {
            $fatal[] = 'The invoice has no lines — there is nothing to describe (mandatory field 6).';
        }
        foreach ($lines as $line) {
            if (trim((string) $line->description) === '') {
                $fatal[] = "Line {$line->line_no} has no description of the goods or nature of the service (mandatory field 6).";
            }
        }

        // (b) VAT shown as a separate amount, and (7) the VATable/exempt/
        // zero-rated breakdown
        if ($vatRegistered) {
            $vatable = (int) $invoice->net_centavos - (int) $invoice->exempt_centavos - (int) $invoice->zero_rated_centavos;

            if ($vatable > 0 && (int) $invoice->vat_centavos === 0) {
                $fatal[] = 'VATable sales are present but no VAT amount is shown as a separate item (mandatory field 8).';
            }
        } elseif ((int) $invoice->vat_centavos > 0) {
            $fatal[] = 'A NON-VAT registrant cannot show a VAT amount on an invoice.';
        }

        // (c) buyer identity — mandatory at ₱1,000 to a VAT-registered buyer
        $buyerIdentityRequired = $buyer !== null
            && (bool) $buyer->is_vat_registered
            && (int) $invoice->total_centavos >= self::BUYER_TIN_THRESHOLD_CENTAVOS;

        $missingBuyerFields = $this->missingBuyerFields($invoice);

        if ($missingBuyerFields !== []) {
            $message = 'The buyer\'s '.implode(', ', $missingBuyerFields).' is missing (mandatory field 11).';
            $buyerIdentityRequired ? $fatal[] = $message : $warnings[] = $message;
        }

        // (10) the words that must appear prominently
        if ((int) $invoice->exempt_centavos > 0 || (int) $invoice->zero_rated_centavos > 0) {
            $warnings[] = 'Exempt or zero-rated components are present — the template must print "VAT-exempt sale" / "zero-rated sale" prominently (mandatory field 10).';
        }

        return ['fatal' => $fatal, 'warnings' => $warnings];
    }

    /**
     * Hard gate, run BEFORE the serial is drawn so a rejected invoice burns
     * no number (CLAUDE.md #6). The serial itself is therefore exempt here —
     * `check()` still requires it for anything being rendered or delivered.
     */
    public function assertIssuable(SalesInvoice $invoice): void
    {
        $result = $this->check($invoice, requireSerial: $invoice->invoice_number !== null);

        if ($result['fatal'] !== []) {
            throw new InvoiceNotCompliant(
                "This invoice cannot be issued — the buyer would lose the input-VAT claim:\n- "
                .implode("\n- ", $result['fatal'])
            );
        }
    }

    /** @return list<string> */
    private function missingBuyerFields(SalesInvoice $invoice): array
    {
        $buyer = $invoice->partner;

        if ($buyer === null) {
            return ['registered name, address and TIN'];
        }

        $missing = [];
        if (trim((string) $buyer->registered_name) === '') {
            $missing[] = 'registered name';
        }
        if (trim((string) $buyer->address) === '') {
            $missing[] = 'address';
        }
        if (trim((string) $buyer->tin) === '') {
            $missing[] = 'TIN';
        }

        return $missing;
    }
}
