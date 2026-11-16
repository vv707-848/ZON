@extends('site.layout')
@php use App\Support\Money; use App\Support\Site\Format; use App\Support\Site\Qr; use App\Support\Site\Site; @endphp
@section('title', ($invoice->qr_tlv ? 'فاتورة ضريبية مبسطة' : 'إيصال').' '.$invoice->number)
@section('robots', 'noindex, nofollow')
@section('canonical', Site::url('account/invoices'))

@section('content')
<article class="doc invoice">
    <div class="licence">
        <div>
            <h1>{{ $invoice->qr_tlv ? 'فاتورة ضريبية مبسطة' : 'إيصال دفع' }}</h1>
            <p class="meta"><span class="num">{{ $invoice->number }}</span> · {{ Format::dateTime($invoice->issued_at) }}</p>
        </div>
        @if ($invoice->qr_tlv){!! Qr::svg($invoice->qr_tlv, 'رمز الفاتورة لهيئة الزكاة والضريبة والجمارك') !!}@endif
    </div>
    <dl class="kv">
        <dt>البائع</dt><dd>{{ $seller }}</dd>
        @if ($vatNumber !== '')<dt>الرقم الضريبي</dt><dd><span class="num">{{ $vatNumber }}</span></dd>@endif
        <dt>المشتري</dt><dd>{{ $invoice->buyer_name }}</dd>
    </dl>
    <table class="section lines">
        {{-- No VAT on it (not registered when it was issued): no tax columns, as a receipt of a seller outside VAT --}}
        @php $taxed = $invoice->vat_halalas > 0; @endphp
        <thead><tr><th>البيان</th><th>الكمية</th>@if ($taxed)<th>قبل الضريبة</th><th>الضريبة</th>@endif<th>الإجمالي</th></tr></thead>
        <tbody>
            @foreach ($invoice->lines ?? [] as $line)
                <tr><td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td>
                    @if ($taxed)<td>{{ Format::price((float) (new Money((int) $line['subtotal']))->riyals()) }}</td>
                    <td>{{ Format::price((float) (new Money((int) $line['vat']))->riyals()) }}</td>@endif
                    <td>{{ Format::price((float) (new Money((int) $line['total']))->riyals()) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="{{ $taxed ? 4 : 2 }}">الإجمالي{{ $taxed ? ' شامل الضريبة' : '' }}</th><th>{{ Format::price((float) (new Money($invoice->total_halalas))->riyals()) }}</th></tr></tfoot>
    </table>
    @unless ($invoice->qr_tlv)<p class="hint section">المنصة غير مسجّلة في ضريبة القيمة المضافة حين صدر هذا الإيصال.</p>@endunless
</article>
@endsection
