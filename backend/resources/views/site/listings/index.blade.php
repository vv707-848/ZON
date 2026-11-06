@extends('site.layout')
@php
    use App\Enums\PropertyType;
    use App\Enums\Purpose;
    use App\Support\Site\Browse;
    $page = $filters['page'] ?? 1;
@endphp

@section('title', $title.($page > 1 ? " — صفحة {$page}" : ''))
@section('description', $listings->total() > 0
    ? $title.': '.number_format($listings->total()).' عرضاً مرخّصاً على زون، بالسعر والمساحة والموقع ورقم الترخيص الإعلاني.'
    : 'لا توجد عروض الآن: '.$title.'.')
@section('canonical', $canonical)
{{-- An empty or re-sorted list is not a page worth a search result --}}
@if ($listings->isEmpty() || isset($filters['sort']))
    @section('robots', 'noindex, follow')
@endif

@section('content')
<nav aria-label="مسار الصفحة"><ol class="crumbs">
    <li><a href="{{ \App\Support\Site\Site::url() }}">الرئيسية</a></li>
    <li><a href="{{ Browse::url([]) }}">العروض</a></li>
    @isset($filters['city'])<li><a href="{{ Browse::url(['city' => $filters['city']]) }}">{{ $filters['city'] }}</a></li>@endisset
    @isset($filters['neighborhood'])<li>{{ $filters['neighborhood'] }}</li>@endisset
</ol></nav>

<div class="section-head"><h1>{{ $title }}</h1><span class="muted small">{{ $listings->total() === 0 ? 'لا عروض' : \App\Support\Site\Format::counted($listings->total(), 'عرض واحد', 'عرضان', 'عروض', 'عرضاً') }}</span></div>

<form class="filters" method="get" action="{{ Browse::url([]) }}" role="search" aria-label="تصفية العروض">
    <div class="field">
        <label for="f-city">المدينة</label>
        <select class="input" id="f-city" name="city">
            <option value="">كل المدن</option>
            @foreach (array_keys($cities) as $city)
                <option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="f-hood">الحي</label>
        <select class="input" id="f-hood" name="neighborhood" @disabled(! isset($filters['city']))>
            <option value="">{{ isset($filters['city']) ? 'كل الأحياء' : 'اختر المدينة أولاً' }}</option>
            @foreach ($cities[$filters['city'] ?? ''] ?? [] as $hood)
                <option value="{{ $hood }}" @selected(($filters['neighborhood'] ?? '') === $hood)>{{ $hood }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="f-type">النوع</label>
        <select class="input" id="f-type" name="type">
            <option value="">كل الأنواع</option>
            @foreach (PropertyType::cases() as $type)
                <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="f-purpose">الغرض</label>
        <select class="input" id="f-purpose" name="purpose">
            <option value="">بيع وإيجار</option>
            @foreach (Purpose::cases() as $purpose)
                <option value="{{ $purpose->value }}" @selected(($filters['purpose'] ?? '') === $purpose->value)>{{ $purpose->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="f-sort">الترتيب</label>
        <select class="input" id="f-sort" name="sort">
            @foreach (Browse::SORTS as $value => $label)
                <option value="{{ $value }}" @selected(($filters['sort'] ?? 'new') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn btn-primary" type="submit"><x-site.icon name="Search" :size="18" />عرض النتائج</button>
</form>

<div class="section">
    @if ($listings->isEmpty())
        <div class="empty">
            <x-site.icon name="Inbox" :size="32" />
            <p>لا توجد عروض بهذه المواصفات الآن.</p>
            @if ($filters !== [])<p><a class="btn btn-tonal" href="{{ Browse::url(isset($filters['city']) ? ['city' => $filters['city']] : []) }}">وسّع البحث</a></p>@endif
        </div>
    @else
        <div class="grid">
            @foreach ($listings as $listing)
                @include('site.partials.listing-card', ['listing' => $listing, 'level' => 2])
            @endforeach
        </div>
        @if ($listings->hasPages())
            <nav class="pager" aria-label="الصفحات">
                @php $base = array_diff_key($filters, ['page' => true]); @endphp
                @if (! $listings->onFirstPage())<a href="{{ Browse::url($page > 2 ? $base + ['page' => $page - 1] : $base) }}" rel="prev">السابقة</a>@endif
                <span aria-current="page"><span class="num">{{ $listings->currentPage() }}</span></span>
                @if ($listings->hasMorePages())<a href="{{ Browse::url($base + ['page' => $page + 1]) }}" rel="next">التالية</a>@endif
            </nav>
        @endif
    @endif
</div>
@endsection
