@extends('site.layout')
@php use App\Support\Site\Site; use App\Support\Site\Browse; @endphp

@section('content')
<section class="hero" aria-labelledby="hero-title">
    <h1 id="hero-title">عروض كثيرة وطلبات كثيرة،<br><span class="accent">وزون تطابق بينها.</span></h1>
    <p>سجّل رغبتك مرة واحدة: المدينة والحي والميزانية والغرف، وزون تطابقها مع العروض المرخّصة من الملاك والوسطاء والمطوّرين.</p>
    <div class="actions">
        <a class="btn btn-secondary" href="{{ Site::url('listings') }}"><x-site.icon name="Search" :size="18" />تصفّح العروض</a>
        <a class="btn btn-glass" href="#app"><x-site.icon name="Smartphone" :size="18" />حمّل التطبيق</a>
    </div>
</section>

<section class="section" aria-labelledby="roles-title">
    <div class="section-head"><h2 id="roles-title">لكل طرف في السوق</h2></div>
    <div class="roles">
        <article class="role">
            <div class="icon-tile"><x-site.icon name="ScanSearch" :size="22" /></div>
            <h3>الباحث عن عقار</h3>
            <p>خلّ المنصة تدوّر لك بدل ما تقلّب مئات الإعلانات: عروض من المالك والمطوّر مباشرة، ومطابقة برغبتك.</p>
        </article>
        <article class="role">
            <div class="icon-tile"><x-site.icon name="Briefcase" :size="22" /></div>
            <h3>الوسيط العقاري</h3>
            <p>طلبات الباحثين في منطقتك، وإدارة عملائك وملاكك ومخزونك في مكان واحد، والترخيص الإعلاني برسوم الهيئة فقط.</p>
        </article>
        <article class="role">
            <div class="icon-tile"><x-site.icon name="House" :size="22" /></div>
            <h3>المالك</h3>
            <p>انشر عقارك بعقد وساطة رسمي وترخيص إعلاني نظامي. عرضك يدخل المطابقة ويصل لمن يبحث عن مثله.</p>
        </article>
    </div>
</section>

<section class="section" aria-labelledby="how-title">
    <div class="section-head"><h2 id="how-title">كيف تعمل المطابقة</h2></div>
    <ol class="steps">
        <li><h3>تسجّل رغبتك</h3><p>المدينة والأحياء والنوع والميزانية وما لا تتنازل عنه.</p></li>
        <li><h3>زون تبحث وترتّب</h3><p>في العروض المنشورة بترخيص إعلاني ساري، وتقدّم الأقرب لرغبتك.</p></li>
        <li><h3>تحكم أنت</h3><p>تحت كل نتيجة «مطابق» أو «غير مطابق» مع سببك، ورأيك يحسّن المطابقة.</p></li>
    </ol>
</section>

@if ($latest->isNotEmpty())
    <section class="section" aria-labelledby="latest-title">
        <div class="section-head"><h2 id="latest-title">أحدث العروض</h2><a href="{{ Site::url('listings') }}">كل العروض</a></div>
        <div class="grid">
            @foreach ($latest as $listing)
                @include('site.partials.listing-card', ['listing' => $listing])
            @endforeach
        </div>
    </section>
@endif

@if ($cities !== [])
    <section class="section" aria-labelledby="cities-title">
        <div class="section-head"><h2 id="cities-title">تصفّح حسب المدينة</h2></div>
        <div class="places">
            @foreach ($cities as $city => $count)
                <a href="{{ Browse::url(['city' => $city]) }}">{{ $city }}<span class="num">{{ number_format($count) }}</span></a>
            @endforeach
        </div>
    </section>
@endif

<section class="section" id="app" aria-labelledby="app-title">
    <div class="cta">
        <h2 id="app-title">زون في جوالك</h2>
        <p>التواصل مع المعلنين، ورغباتك ونتائجها اليومية، ونشر عرضك أو طلبك: كلها في التطبيق.</p>
        @include('site.partials.get-app', ['tone' => 'btn-secondary'])
    </div>
</section>
@endsection

@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'زون', 'url' => Site::url(), 'logo' => Site::url('site/icon-192.png')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush
