@extends('site.layout')
@php
    use App\Support\Site\Format;
    use App\Support\Site\Qr;
    use App\Support\Site\Site;
    $specs = Format::specs($unit);
    $licence = $unit->licence;
    $url = Site::url('projects/'.$project->id.'/units/'.$unit->id);
@endphp

@section('title', $unit->model_name.' — '.$project->title)
@section('description', implode(' · ', array_filter([Format::price((float) $unit->price), $unit->property_type->label(), $project->neighborhood.'، '.$project->city])))
@section('canonical', $url)

@section('content')
<nav aria-label="مسار الصفحة"><ol class="crumbs">
    <li><a href="{{ Site::url() }}">الرئيسية</a></li>
    <li><a href="{{ Site::url('projects/'.$project->id) }}">{{ $project->title }}</a></li>
    <li>{{ $unit->model_name }}</li>
</ol></nav>

<div class="detail">
    <div>
        @if ($unit->media->isNotEmpty())
            <div class="gallery" tabindex="0" aria-label="صور الوحدة، مرّر للمزيد">
                @foreach ($unit->media as $i => $photo)
                    <figure><img src="{{ $photo->url('large') }}" alt="{{ $unit->model_name }} — صورة {{ $i + 1 }}" @if ($i > 0) loading="lazy" @endif decoding="async"></figure>
                @endforeach
            </div>
        @endif
        <div @class(['panel', 'section' => $unit->media->isNotEmpty()])>
            <span class="badge badge-green">{{ $unit->property_type->label() }}</span>
            <h1 class="title-top">{{ $unit->model_name }}</h1>
            <div class="price-big only-phone">{{ Format::price((float) $unit->price) }}</div>
            <p class="card-place"><x-site.icon name="Building2" :size="16" />{{ $project->title }} · {{ $project->neighborhood }}، {{ $project->city }}</p>
            @if ($specs !== [])
                <dl class="facts section">
                    @foreach ($specs as $label => $value)
                        <div class="fact"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>
        @if (trim((string) $unit->publicDescription()) !== '')
            <div class="panel"><h2>الوصف</h2><p class="prose">{{ $unit->publicDescription() }}</p></div>
        @endif
        @if ($unit->payment_method)
            <div class="panel"><h2>طريقة الدفع</h2><p class="prose">{{ $unit->payment_method }}</p></div>
        @endif
    </div>
    <aside class="aside" aria-label="السعر والترخيص">
        <div class="panel only-desk">
            <div class="price-big">{{ Format::price((float) $unit->price) }}</div>
            <p class="muted small">{{ $unit->available_count > 0 ? 'المتاح: '.Format::units($unit->available_count) : 'مباع بالكامل' }}</p>
        </div>
        @if ($licence)
            <div class="panel">
                <h2>الترخيص الإعلاني</h2>
                <div class="licence">
                    <dl class="kv">
                        <dt>رقم الترخيص</dt><dd><span class="num">{{ $licence->licence_number }}</span></dd>
                        @if ($licence->expires_at)<dt>ينتهي في</dt><dd>{{ Format::date($licence->expires_at) }}</dd>@endif
                    </dl>
                    @if ($licence->qr_url)
                        <a href="{{ $licence->qr_url }}" rel="nofollow noopener" target="_blank">{!! Qr::svg($licence->qr_url, 'رمز التحقق من الترخيص لدى الهيئة العامة للعقار') !!}</a>
                    @endif
                </div>
            </div>
        @endif
        <div class="cta">
            <h2>تواصل مع المطوّر</h2>
            <p>الحجز والتواصل المباشر في تطبيق زون.</p>
            @include('site.partials.get-app', ['tone' => 'btn-secondary btn-block'])
        </div>
    </aside>
</div>
@endsection
