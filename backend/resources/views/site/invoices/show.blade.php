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
        <thead><tr><th>البيان</th><th>الكمية</th><th>قبل الضريبة</th><th>الضريبة</th><th>الإجمالي</th></tr></thead>
        <tbody>
            @foreach ($invoice->lines ?? [] as $line)
                <tr><td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td>
                    <td>{{ Format::price((float) (new Money((int) $line['subtotal']))->riyals()) }}</td>
                    <td>{{ Format::price((float) (new Money((int) $line['vat']))->riyals()) }}</td>
                    <td>{{ Format::price((float) (new Money((int) $line['total']))->riyals()) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="4">الإجمالي{{ $invoice->vat_halalas > 0 ? ' شامل الضريبة' : '' }}</th><th>{{ Format::price((float) (new Money($invoice->total_halalas))->riyals()) }}</th></tr></tfoot>
    </table>
    @unless ($invoice->qr_tlv)<p class="hint section">المنصة غير مسجّلة في ضريبة القيمة المضافة حين صدر هذا الإيصال.</p>@endunless
</article>
@endsection
