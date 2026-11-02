<?php

namespace App\Integrations\Invoicing;

use App\Models\Invoice;
use App\Support\Money;

/** ZATCA phase-1 QR built locally: tags 1–5, each tag · length · UTF-8 value. */
class LocalInvoicing implements InvoicingGateway
{
    public function name(): string
    {
        return 'local';
    }

    public function qr(Invoice $invoice, string $sellerName, string $vatNumber): string
    {
        $fields = [
            1 => $sellerName,
            2 => $vatNumber,
            3 => $invoice->issued_at->toIso8601ZuluString(),
            4 => (new Money($invoice->total_halalas))->riyals(),
            5 => (new Money($invoice->vat_halalas))->riyals(),
        ];
        $tlv = '';
        foreach ($fields as $tag => $value) {
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }
}
