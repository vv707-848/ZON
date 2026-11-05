@extends('site.layout')
@php use App\Enums\PaymentStatus; use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'نتيجة الدفع')
@section('robots', 'noindex, nofollow')
@section('canonical', Site::url('packages'))

@section('content')
<div class="narrow">
    @switch ($payment->status)
        @case (PaymentStatus::Paid)
            <div class="note note-green" role="status"><strong>تم الدفع.</strong>
                @if ($payment->target instanceof \App\Models\Package && $subscription?->ends_at)
                    باقتك «{{ $payment->target->name }}» سارية حتى {{ Format::date($subscription->ends_at) }}، في الموقع والتطبيق.
                @endif
            </div>
            @break
        @case (PaymentStatus::Unapplied)
            <div class="note note-gold" role="status"><strong>وصل المبلغ</strong>، لكن تعذّر تطبيقه على الحساب. يراجعه فريق زون ويعيده إليك إن لزم.</div>
            @break
        @case (PaymentStatus::Failed)
            <div class="note note-danger" role="alert"><strong>لم يكتمل الدفع.</strong> لم يُخصم منك شيء.
                <a class="link" href="{{ Site::url('pay/'.$payment->public_id) }}">حاول مرة أخرى</a></div>
            @break
        @default
            <div class="note" role="status"><strong>الدفع قيد المعالجة.</strong> يصل التأكيد من ميسّر خلال دقائق، ونطبّقه على حسابك تلقائياً.</div>
    @endswitch

    <div class="panel section">
        <dl class="kv">
            <dt>رقم العملية</dt><dd><span class="num">{{ $payment->public_id }}</span></dd>
            <dt>المبلغ</dt><dd>{{ Format::price((float) $payment->amount()->riyals()) }}</dd>
            @if ($payment->paid_at)<dt>التاريخ</dt><dd>{{ Format::dateTime($payment->paid_at) }}</dd>@endif
        </dl>
        @if ($payment->invoice)
            <p class="section"><a class="btn btn-secondary" href="{{ Site::url('account/invoices/'.$payment->invoice->id) }}"><x-site.icon name="FileText" :size="18" />{{ $payment->invoice->qr_tlv ? 'الفاتورة' : 'الإيصال' }}</a></p>
        @endif
    </div>
    <p class="section"><a class="link" href="{{ Site::url('account') }}">حسابي</a></p>
</div>
@endsection
