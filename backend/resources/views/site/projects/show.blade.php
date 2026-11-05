@extends('site.layout')
@php
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $developer = $project->developer;
    $available = $units->where('available_count', '>', 0);
    $start = $available->isEmpty() ? null : (float) $available->min('price');
    $url = Site::url('projects/'.$project->id);
@endphp

@section('title', $project->title)
@section('description', implode(' · ', array_filter(['مشروع '.$project->delivery->label(), $project->neighborhood.'، '.$project->city,
    $start !== null ? 'ابتداءً من '.Format::price($start) : null, $units->isNotEmpty() ? 'نماذج الوحدات: '.$units->count() : null])))
@section('canonical', $url)
@if ($project->media->isNotEmpty())
    @section('og_image', Site::url('projects/'.$project->id.'/image'))
@endif

@section('content')
<nav aria-label="مسار الصفحة"><ol class="crumbs">
    <li><a href="{{ Site::url() }}">الرئيسية</a></li>
    <li>{{ $project->city }}</li>
    <li>{{ $project->title }}</li>
</ol></nav>

<div class="detail">
    <div>
        @if ($project->media->isNotEmpty())
            <div class="gallery" tabindex="0" aria-label="صور المشروع، مرّر للمزيد">
                @foreach ($project->media as $i => $photo)
                    <figure><img src="{{ $photo->url('large') }}" alt="{{ $project->title }} — صورة {{ $i + 1 }}" @if ($i > 0) loading="lazy" @endif decoding="async"></figure>
                @endforeach
            </div>
        @endif

        <div @class(['panel', 'section' => $project->media->isNotEmpty()])>
            <div class="chips">
                <span class="badge badge-green">مشروع {{ $project->delivery->label() }}</span>
                @if ($project->featured_until?->isFuture())<span class="badge badge-gold"><x-site.icon name="Crown" :size="12" :stroke="2.2" />مميز</span>@endif
            </div>
            <h1 class="title-top">{{ $project->title }}</h1>
            @if ($start !== null)<div class="price-big only-phone"><span class="small muted">ابتداءً من</span> {{ Format::price($start) }}</div>@endif
            <p class="card-place"><x-site.icon name="MapPin" :size="16" />{{ $project->neighborhood }}، {{ $project->city }}</p>
            <dl class="facts section">
                <div class="fact"><dt>النوع</dt><dd>{{ $project->property_type->label() }}</dd></div>
                @if ($project->land_use)<div class="fact"><dt>الاستخدام</dt><dd>{{ $project->land_use->label() }}</dd></div>@endif
                @if ($project->total_units)<div class="fact"><dt>عدد الوحدات</dt><dd><span class="num">{{ $project->total_units }}</span></dd></div>@endif
                <div class="fact"><dt>المتاح</dt><dd><span class="num">{{ (int) $available->sum('available_count') }}</span></dd></div>
            </dl>
        </div>

        @if (trim((string) $project->publicDescription()) !== '')
            <div class="panel"><h2>عن المشروع</h2><p class="prose">{{ $project->publicDescription() }}</p></div>
        @endif

        @if ($units->isNotEmpty())
            <div class="panel">
                <h2>نماذج الوحدات</h2>
                <div class="units">
                    @foreach ($units as $unit)
                        <a class="unit" href="{{ Site::url('projects/'.$project->id.'/units/'.$unit->id) }}">
                            <span>
                                <strong>{{ $unit->model_name }}</strong><br>
                                <span class="muted small">{{ $unit->property_type->label() }}
                                    @if ($unit->area !== null) · {{ Format::area((float) $unit->area) }}@endif
                                    @if ($unit->bedrooms !== null) · {{ Format::rooms($unit->bedrooms) }}@endif
                                    · {{ $unit->available_count > 0 ? 'المتاح: '.Format::units($unit->available_count) : 'مباع بالكامل' }}</span>
                            </span>
                            <span class="card-price">{{ Format::price((float) $unit->price) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <aside class="aside" aria-label="السعر والمطوّر">
        <div class="panel only-desk">
            @if ($start !== null)
                <p class="muted small">ابتداءً من</p>
                <div class="price-big">{{ Format::price($start) }}</div>
            @else
                <p class="muted">لا وحدات متاحة الآن.</p>
            @endif
        </div>
        <div class="panel">
            <h2>المطوّر</h2>
            <a class="person" href="{{ Site::url('users/'.rawurlencode((string) $developer->username)) }}">
                <span class="avatar" aria-hidden="true">{{ mb_substr($developer->name, 0, 1) }}</span>
                <span class="who"><span class="name">{{ $developer->name }}</span><br><span class="muted small">مطوّر عقاري</span></span>
            </a>
        </div>
        <div class="cta">
            <h2>تواصل مع المطوّر</h2>
            <p>الحجز والتواصل المباشر مع المطوّر في تطبيق زون.</p>
            @include('site.partials.get-app', ['tone' => 'btn-secondary btn-block'])
        </div>
    </aside>
</div>
@endsection
