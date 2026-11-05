@extends('site.layout')
@php use App\Support\Money; use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'الفواتير')
@section('robots', 'noindex')
@section('canonical', Site::url('account/invoices'))

@section('content')
<div class="narrow">
    <h1>الفواتير</h1>
    @if ($invoices->isEmpty())
        <div class="empty section"><x-site.icon name="FileText" :size="32" /><p>لا فواتير بعد.</p></div>
    @else
        <ul class="list-links section">
            @foreach ($invoices as $invoice)
                <li><a href="{{ Site::url('account/invoices/'.$invoice->id) }}"><x-site.icon name="FileText" :size="20" />
                    <span><span class="num">{{ $invoice->number }}</span><br><span class="muted small">{{ Format::date($invoice->issued_at) }} · {{ Format::price((float) (new Money($invoice->total_halalas))->riyals()) }}</span></span>
                    <x-site.icon name="ChevronLeft" :size="18" /></a></li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
