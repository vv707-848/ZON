@extends('site.layout')
@php use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'الدفع (محاكاة)')
@section('robots', 'noindex, nofollow')
@section('canonical', Site::url('packages'))

@section('content')
<div class="narrow">
    <h1>الدفع</h1>
    <div class="note note-gold section" role="alert"><strong>محاكاة: لا تتحرك أي أموال.</strong> هذا الخادم يعمل بلا مفاتيح ميسّر، والدفع الحقيقي يبدأ حين تُضاف (docs/integrations.md).</div>
    <div class="panel section">
        <dl class="kv">
            <dt>الشراء</dt><dd>{{ $payment->purpose->label() }}{{ $payment->target instanceof \App\Models\Package ? ' — '.$payment->target->name : '' }}</dd>
            <dt>المبلغ</dt><dd>{{ Format::price((float) $payment->amount()->riyals()) }}</dd>
        </dl>
        <div class="two section">
            <a class="btn btn-primary" href="{{ $returnUrl }}?id={{ urlencode($simulation['reference']) }}">إتمام الدفع (محاكاة)</a>
            <a class="btn btn-ghost" href="{{ $returnUrl }}?id={{ urlencode($simulation['decline_reference']) }}">رفض البطاقة (محاكاة)</a>
        </div>
    </div>
</div>
@endsection
