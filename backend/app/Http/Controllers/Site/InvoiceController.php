<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * The account's invoices on the website, printable: the same rows the app lists (IssueInvoice). While the platform
 * is VAT-registered an invoice is a simplified tax invoice with its ZATCA QR; before that it is a payment receipt.
 */
class InvoiceController extends Controller
{
    public function index(): View
    {
        return view('site.invoices.index', ['invoices' => Invoice::query()->where('customer_id', $this->me()->id)->latest('issued_at')->paginate(20)]);
    }

    public function show(Invoice $invoice): View
    {
        abort_unless($invoice->customer_id === $this->me()->id, 404);

        return view('site.invoices.show', [
            'invoice' => $invoice,
            'seller' => Settings::string(Setting::SellerName),
            'vatNumber' => Settings::bool(Setting::VatRegistered) ? Settings::string(Setting::VatNumber) : '',
        ]);
    }

    private function me(): Customer
    {
        $me = Auth::guard('site')->user();
        abort_unless($me instanceof Customer, 403);

        return $me;
    }
}
