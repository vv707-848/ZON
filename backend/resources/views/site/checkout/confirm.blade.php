@extends('site.layout')
@php use App\Enums\PackagePeriod; use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'الاشتراك في '.$package->name)
@section('robots', 'noindex')
@section('canonical', Site::url('packages'))

@section('content')
<div class="narrow">
    <h1>{{ $package->name }}</h1>
    @include('site.partials.errors')
    @if (! $fits)
        <div class="note note-gold section">هذه الباقة لـ{{ $package->audience->label() }}، وحسابك «{{ $customer->role->label() }}». <a class="link" href="{{ Site::url('packages') }}">اختر من باقات حسابك</a>.</div>
    @else
        <div class="panel section">
            <div class="price-big">{{ Format::price((float) $package->price()->riyals()) }}</div>
            <p class="muted">{{ $package->period === PackagePeriod::Year ? 'لسنة' : 'لشهر' }} من اليوم{{ $current && $current->package_id === $package->id && $current->ends_at ? '، تبدأ بعد نهاية اشتراكك الحالي في '.Format::date($current->ends_at) : '' }}. بلا تجديد تلقائي.</p>
            @if (($package->features ?? []) !== [])
                <ul class="ticks section">@foreach ($package->features as $feature)<li><x-site.icon name="Check" :size="16" :stroke="2.4" />{{ $feature }}</li>@endforeach</ul>
            @endif
        </div>
        @if ($store)
            <div class="note note-gold">اشتراكك الحالي من {{ $store === 'apple' ? 'App Store' : 'Google Play' }} ويتجدد تلقائياً حتى تلغيه من إعدادات المتجر. الشراء هنا لا يوقفه.</div>
        @endif
        <form class="section" method="post" action="{{ Site::url('subscribe/'.$package->code) }}">
            @csrf
            <button class="btn btn-primary btn-block" type="submit">متابعة إلى الدفع</button>
        </form>
        <p class="hint section">الحساب: {{ $customer->name }} · الباقة الحالية: {{ $currentPlan->name }}</p>
    @endif
</div>
@endsection
