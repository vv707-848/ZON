@extends('site.layout')
@php
    use App\Enums\PackageAudience;
    use App\Enums\PackagePeriod;
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $period = fn ($p) => match ($p) { PackagePeriod::Month => 'شهرياً', PackagePeriod::Year => 'سنوياً', default => '' };
@endphp
@section('title', 'الباقات والأسعار')
@section('description', 'باقات زون وأسعارها للباحث والوسيط والمالك والمطوّر، كما في التطبيق.')
@section('canonical', Site::url('packages'))

@section('content')
<h1>الباقات والأسعار</h1>
<p class="muted">الأسعار نفسها في التطبيق.@if ($vat) شاملة ضريبة القيمة المضافة {{ rtrim(rtrim(number_format((float) $vat * 100, 2), '0'), '.') }}٪.@endif
    الاشتراك من الموقع لمدة الباقة بلا تجديد تلقائي، ونذكّرك قبل انتهائه بثلاثة أيام. وفي التطبيق يتجدد عبر المتجر.</p>

@foreach ($audiences as [$audience, $plans])
    <section class="section" aria-labelledby="aud-{{ $audience->value }}">
        <div class="section-head"><h2 id="aud-{{ $audience->value }}">{{ match ($audience) { PackageAudience::Seeker => 'للباحث عن عقار', PackageAudience::Broker => 'للوسيط العقاري', PackageAudience::Owner => 'للمالك', PackageAudience::Developer => 'للمطوّر العقاري' } }}</h2></div>
        <div class="grid">
            @foreach ($plans as $plan)
                <article @class(['plan', 'plan-paid' => $plan->isPurchasable()])>
                    <h3>{{ $plan->name }}</h3>
                    <div class="plan-price">
                        @if ($plan->isPurchasable())
                            {{ Format::price((float) $plan->price()->riyals()) }} <span class="small">{{ $period($plan->period) }}</span>
                        @elseif ($audience === PackageAudience::Developer)
                            بعقد تسويق
                        @elseif ($audience === PackageAudience::Seeker)
                            مجاني
                        @else
                            بلا اشتراك
                        @endif
                    </div>
                    @if (($plan->features ?? []) !== [])
                        <ul class="ticks">@foreach ($plan->features as $feature)<li><x-site.icon name="Check" :size="16" :stroke="2.4" />{{ $feature }}</li>@endforeach</ul>
                    @endif
                    @if ($plan->isPurchasable())
                        <a class="btn btn-primary btn-block plan-cta" href="{{ Site::url('subscribe/'.$plan->code) }}">اشترك من الموقع</a>
                    @elseif ($audience === PackageAudience::Developer)
                        <a class="btn btn-ghost btn-block plan-cta" href="{{ Site::url('complaints') }}?type=question">تواصل معنا</a>
                    @endif
                </article>
            @endforeach
        </div>
        @if ($audience === PackageAudience::Owner)
            <p class="note section">المالك يدفع رسوم الترخيص الإعلاني للهيئة كما هي ({{ Format::price((float) $licenceFee->riyals()) }})، وسعي زون {{ $ownerPercent }}٪ من سعر البيع بعد البيع فقط.</p>
        @elseif ($audience === PackageAudience::Broker)
            <p class="note section">الوسيط بلا اشتراك ينشر، وتشارك زون {{ $brokerShare }}٪ من سعيه عند إتمام صفقة عبر عرض منشور في المنصة.</p>
        @endif
    </section>
@endforeach

<section class="section" aria-labelledby="one-off">
    <div class="section-head"><h2 id="one-off">خدمات تُشترى مرة واحدة من التطبيق</h2></div>
    <div class="panel">
        <dl class="kv">
            @foreach ($oneOff as $label => $price)
                <dt>{{ $label }}</dt><dd>{{ Format::price((float) $price->riyals()) }} <span class="muted small">لمدة {{ Format::counted($boostDays, 'يوم واحد', 'يومين', 'أيام', 'يوماً') }}</span></dd>
            @endforeach
        </dl>
    </div>
</section>
@endsection
