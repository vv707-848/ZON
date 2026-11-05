@extends('site.layout')
@php
    use App\Enums\CustomerRole;
    use App\Enums\FalStatus;
    use App\Support\Site\Browse;
    use App\Support\Site\Format;
    use App\Support\Site\Qr;
    use App\Support\Site\Site;
    $advertiser = $listing->customer;
    $licence = $listing->licence;
    $specs = Format::specs($listing);
    $price = Format::price((float) $listing->price, $listing->purpose);
    $place = $listing->neighborhood.'، '.$listing->city;
    $summary = implode(' · ', array_filter([$price, $listing->property_type->label().' '.$listing->purpose->label(), $place,
        $listing->bedrooms !== null ? Format::rooms($listing->bedrooms) : null,
        $listing->area_built !== null ? Format::area((float) $listing->area_built) : ($listing->area_land !== null ? Format::area((float) $listing->area_land) : null)]));
    $url = Site::url('listings/'.$listing->id);
    $map = $listing->lat !== null && $listing->lng !== null ? 'https://www.google.com/maps?q='.$listing->lat.','.$listing->lng
        : (is_string($listing->maps_link) && preg_match('#^https://#', $listing->maps_link) ? $listing->maps_link : null);
@endphp

@section('title', $listing->title)
@section('description', $summary)
@section('canonical', $url)
@if ($listing->media->isNotEmpty())
    @section('og_image', Site::url('listings/'.$listing->id.'/image'))
@endif

@section('content')
<nav aria-label="مسار الصفحة"><ol class="crumbs">
    <li><a href="{{ Site::url() }}">الرئيسية</a></li>
    <li><a href="{{ Browse::url(['city' => $listing->city]) }}">{{ $listing->city }}</a></li>
    <li><a href="{{ Browse::url(['city' => $listing->city, 'neighborhood' => $listing->neighborhood]) }}">{{ $listing->neighborhood }}</a></li>
    <li><a href="{{ Browse::url(['city' => $listing->city, 'neighborhood' => $listing->neighborhood, 'type' => $listing->property_type->value]) }}">{{ $listing->property_type->plural() }}</a></li>
</ol></nav>

<div class="detail">
    <div>
        @if ($listing->media->isNotEmpty())
            <div class="gallery" tabindex="0" aria-label="صور العرض، مرّر للمزيد">
                @foreach ($listing->media as $i => $photo)
                    <figure><img src="{{ $photo->url('large') }}" alt="{{ $listing->title }} — صورة {{ $i + 1 }}" @if ($i > 0) loading="lazy" @endif decoding="async"></figure>
                @endforeach
            </div>
            @if ($listing->media->count() > 1)<p class="gallery-count"><span class="num">{{ $listing->media->count() }}</span> صور — مرّر لرؤيتها</p>@endif
        @endif

        <div @class(['panel', 'section' => $listing->media->isNotEmpty()])>
            <div class="chips">
                @if ($listing->isFeatured())<span class="badge badge-gold"><x-site.icon name="Crown" :size="12" :stroke="2.2" />مميز</span>@endif
                @if ($listing->isUrgent())<span class="badge badge-danger">عاجل</span>@endif
                <span class="badge badge-green">{{ $listing->property_type->label() }} {{ $listing->purpose->label() }}</span>
            </div>
            <h1 class="title-top">{{ $listing->title }}</h1>
            <div class="price-big only-phone">{{ $price }}</div>
            <p class="card-place"><x-site.icon name="MapPin" :size="16" />{{ $place }}
                @if ($map)· <a href="{{ $map }}" rel="nofollow noopener" target="_blank">الموقع على الخريطة</a>@endif</p>

            @if ($specs !== [])
                <dl class="facts section">
                    @foreach ($specs as $label => $value)
                        <div class="fact"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>

        @if (trim((string) $listing->publicDescription()) !== '')
            <div class="panel">
                <h2>الوصف</h2>
                <p class="prose">{{ $listing->publicDescription() }}</p>
            </div>
        @endif

        @if (($listing->features ?? []) !== [] || $listing->has_deed || $listing->bank_accepted)
            <div class="panel">
                <h2>المزايا</h2>
                <ul class="ticks">
                    @if ($listing->has_deed)<li><x-site.icon name="Check" :size="16" :stroke="2.4" />صك</li>@endif
                    @if ($listing->bank_accepted)<li><x-site.icon name="Check" :size="16" :stroke="2.4" />يقبل التمويل البنكي</li>@endif
                    @foreach ($listing->features ?? [] as $feature)
                        <li><x-site.icon name="Check" :size="16" :stroke="2.4" />{{ $feature }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <aside class="aside" aria-label="السعر والمعلن والترخيص">
        <div class="panel only-desk">
            <div class="price-big">{{ $price }}</div>
            @if ($listing->negotiable)<p class="muted small">قابل للتفاوض</p>@endif
            @if ($listing->published_at)<p class="muted small">نُشر في {{ Format::date($listing->published_at) }}</p>@endif
        </div>

        <div class="panel">
            <h2>المعلن</h2>
            <a class="person" href="{{ Site::url('users/'.rawurlencode((string) $advertiser->username)) }}">
                <span class="avatar" aria-hidden="true">{{ mb_substr($advertiser->name, 0, 1) }}</span>
                <span class="who">
                    <span class="name">{{ $advertiser->name }}</span><br>
                    <span class="muted small">{{ $advertiser->role === CustomerRole::Broker ? 'وسيط عقاري' : $advertiser->role->label() }}</span>
                </span>
            </a>
            @if ($advertiser->role === CustomerRole::Broker && $advertiser->fal_status === FalStatus::Valid && $advertiser->fal_number)
                <dl class="kv section"><dt>رخصة فال</dt><dd><span class="num">{{ $advertiser->fal_number }}</span> <x-site.icon name="BadgeCheck" :size="16" class="ok" /></dd></dl>
            @endif
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
                        <a href="{{ $licence->qr_url }}" rel="nofollow noopener" target="_blank" title="التحقق من الترخيص لدى الهيئة العامة للعقار">{!! Qr::svg($licence->qr_url, 'رمز التحقق من الترخيص لدى الهيئة العامة للعقار') !!}</a>
                    @endif
                </div>
                @if ($licence->qr_url)<p class="small section"><a class="muted" href="{{ $licence->qr_url }}" rel="nofollow noopener" target="_blank">التحقق من الترخيص لدى الهيئة العامة للعقار</a></p>@endif
            </div>
        @endif

        <div class="cta">
            <h2>تواصل مع المعلن</h2>
            <p>أرقام المعلن والمحادثة معه في تطبيق زون، بعد تسجيل الدخول.</p>
            @include('site.partials.get-app', ['tone' => 'btn-secondary btn-block'])
        </div>
    </aside>
</div>

@if ($similar->isNotEmpty())
    <section class="section" aria-labelledby="similar-title">
        <div class="section-head"><h2 id="similar-title">عروض مشابهة في {{ $listing->city }}</h2>
            <a href="{{ Browse::url(['city' => $listing->city, 'type' => $listing->property_type->value]) }}">المزيد</a></div>
        <div class="grid">
            @foreach ($similar as $other)
                @include('site.partials.listing-card', ['listing' => $other])
            @endforeach
        </div>
    </section>
@endif
@endsection

@push('head')
@php
    $ld = [
        ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $listing->city, 'item' => Browse::url(['city' => $listing->city])],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $listing->neighborhood, 'item' => Browse::url(['city' => $listing->city, 'neighborhood' => $listing->neighborhood])],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $listing->title, 'item' => $url],
        ]],
        array_filter(['@context' => 'https://schema.org', '@type' => 'RealEstateListing', 'name' => $listing->title, 'url' => $url,
            'description' => $summary, 'datePosted' => $listing->published_at?->toIso8601String(),
            'image' => $listing->media->isNotEmpty() ? Site::url('listings/'.$listing->id.'/image') : null,
            'offers' => ['@type' => 'Offer', 'price' => (string) (float) $listing->price, 'priceCurrency' => 'SAR'],
            'contentLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $listing->city, 'addressCountry' => 'SA']]]),
    ];
@endphp
@foreach ($ld as $block)
<script type="application/ld+json">{!! json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endforeach
@endpush
