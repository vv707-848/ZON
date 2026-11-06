<?php

namespace App\Integrations\Invoicing;

use App\Models\Invoice;

/** Tax invoicing. Phase 1 of ZATCA: a QR code on a simplified invoice; phase 2 (integration) replaces this driver. */
interface InvoicingGateway
{
    /** The QR content: TLV (seller, VAT number, time, total, VAT) in base64. */
    public function qr(Invoice $invoice, string $sellerName, string $vatNumber): string;
}
