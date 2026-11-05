@extends('site.layout')
@php use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', 'حسابي')
@section('robots', 'noindex')
@section('canonical', Site::url('account'))

@section('content')
<div class="narrow">
    @if (session('status'))<div class="note note-green" role="status">{{ session('status') }}</div>@endif
    <div class="panel section">
        <div class="person">
            <span class="avatar" aria-hidden="true">{{ mb_substr($customer->name, 0, 1) }}</span>
            <div class="who"><h1>{{ $customer->name }}</h1><span class="muted small">{{ $customer->role->label() }}</span></div>
        </div>
        <dl class="kv section">
            <dt>الباقة</dt><dd>{{ $plan->name }}</dd>
            @if ($subscription?->ends_at)<dt>تنتهي في</dt><dd>{{ Format::date($subscription->ends_at) }}</dd>@endif
        </dl>
    </div>
    <div class="panel">
        <ul class="list-links">
            @if (Route::has('site.packages'))<li><a href="{{ Site::url('packages') }}"><x-site.icon name="Crown" :size="20" />الباقات<x-site.icon name="ChevronLeft" :size="18" /></a></li>@endif
            @if (Route::has('site.invoices'))<li><a href="{{ Site::url('account/invoices') }}"><x-site.icon name="FileText" :size="20" />الفواتير<x-site.icon name="ChevronLeft" :size="18" /></a></li>@endif
            <li><a href="{{ Site::url('account/delete') }}"><x-site.icon name="Trash2" :size="20" />حذف الحساب<x-site.icon name="ChevronLeft" :size="18" /></a></li>
        </ul>
    </div>
    <form method="post" action="{{ Site::url('logout') }}">
        @csrf
        <button class="btn btn-ghost btn-block" type="submit">تسجيل الخروج</button>
    </form>
</div>
@endsection
