@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('title', 'تقرير السوق '.$brief->number)
@section('robots', 'noindex, nofollow')
@section('canonical', Site::url())

@push('head')<script src="{{ Site::asset('print.js') }}" defer></script>@endpush

@section('content')
<article class="doc brief">
    <p class="meta">تقرير سوق من زون · <span class="num">{{ $brief->number }}</span> · {{ $brief->created_at->setTimezone('Asia/Riyadh')->format('Y-m-d') }}</p>
    <h1>{{ $brief->scope() }}</h1>
    <dl class="kv section">
        <dt>أُعدّ لـ</dt><dd>{{ $brief->buyer_name }}</dd>
        <dt>فترة الطلب</dt><dd>آخر {{ $brief->days }} يوماً</dd>
        <dt>يفتح الرابط حتى</dt><dd>{{ $brief->expires_at->setTimezone('Asia/Riyadh')->format('Y-m-d') }}</dd>
    </dl>
    <p class="section no-print"><button type="button" class="btn btn-tonal" data-print>طباعة أو حفظ PDF</button></p>
    @include('site.briefs.body', ['brief' => $brief])
</article>
@endsection
