@extends('site.layout')
@php use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'الدفع')
@section('robots', 'noindex, nofollow')
@section('canonical', Site::url('packages'))
@push('head')
    <link rel="stylesheet" href="https://cdn.moyasar.com/mpf/{{ $version }}/moyasar.css">
    <script src="https://cdn.moyasar.com/mpf/{{ $version }}/moyasar.js" defer></script>
    <script src="{{ Site::asset('pay.js') }}" defer></script>
@endpush

@section('content')
<div class="narrow">
    <h1>الدفع</h1>
    <div class="panel section">
        <dl class="kv">
            <dt>الشراء</dt><dd>{{ $payment->purpose->label() }}{{ $payment->target instanceof \App\Models\Package ? ' — '.$payment->target->name : '' }}</dd>
            <dt>المبلغ</dt><dd>{{ Format::price((float) $payment->amount()->riyals()) }}</dd>
        </dl>
        @if ($payment->status->value === 'failed')<div class="note note-danger section">لم تكتمل المحاولة السابقة. يمكنك المحاولة مرة أخرى.</div>@endif
    </div>
    <div class="panel">
        <div class="mysr-form"></div>
        <p class="note note-danger" id="pay-unavailable" hidden>تعذّر تحميل نموذج الدفع. حدّث الصفحة، أو حاول بعد قليل.</p>
        <p class="hint section">بيانات البطاقة تُكتب في نموذج ميسّر وتذهب إليه مباشرة، ولا تمر بخوادم زون.</p>
    </div>
</div>
<script type="application/json" id="pay-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endsection
