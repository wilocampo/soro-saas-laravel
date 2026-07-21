<?php

namespace Tests\Ledger;

use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\VendorBill;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Supporting documents on a bill (docs/specs/03 §2). The scan is part of the
 * books: same retention rules as the entry it evidences, and it lives in the
 * TENANT database with the document it supports.
 */
class ReceiptAttachmentTest extends LedgerTestCase
{
    private function bill(): VendorBill
    {
        $vendor = Partner::create([
            'code' => 'V-500',
            'is_vendor' => true,
            'registered_name' => 'Supplier Inc.',
            'is_vat_registered' => true,
        ]);

        return VendorBill::create([
            'bill_number' => 'SUP-5001',
            'partner_id' => $vendor->id,
            'bill_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'open',
            'net_centavos' => 500_000,
            'total_centavos' => 500_000,
        ]);
    }

    public function test_a_receipt_scan_attaches_to_a_bill_in_the_tenant_database(): void
    {
        Storage::fake('public');
        $bill = $this->bill();

        // Real bytes: the collection sniffs the file, not the declared type,
        // which is exactly what stops a renamed executable getting in.
        $bill->addMedia(UploadedFile::fake()->createWithContent(
            'supplier-receipt.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
        ))->toMediaCollection(VendorBill::RECEIPTS, 'public');

        $receipts = $bill->fresh()->receipts();

        $this->assertCount(1, $receipts);
        $this->assertSame('application/pdf', $receipts->first()->mime_type);
        $this->assertSame(VendorBill::RECEIPTS, $receipts->first()->collection_name);

        // Tenant data belongs on the tenant connection, not the landlord's.
        $this->assertSame(
            $bill->getConnectionName(),
            $receipts->first()->getConnectionName(),
            'Media rows must live alongside the documents they support.'
        );
    }

    public function test_photos_of_a_receipt_are_accepted_and_executables_are_not(): void
    {
        Storage::fake('public');
        $bill = $this->bill();

        $bill->addMedia(UploadedFile::fake()->image('receipt.jpg'))
            ->toMediaCollection(VendorBill::RECEIPTS, 'public');

        $this->assertCount(1, $bill->fresh()->receipts());

        $this->expectException(FileUnacceptableForCollection::class);

        // Renamed to look like a receipt; the mime sniff sees through it.
        $bill->addMedia(UploadedFile::fake()->createWithContent('receipt.pdf', "MZ\x90\x00executable"))
            ->toMediaCollection(VendorBill::RECEIPTS, 'public');
    }
}
