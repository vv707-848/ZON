@extends('site.layout')
@php
    use App\Enums\CustomerRole;
    use App\Enums\FalStatus;
    use App\Enums\NafathStatus;
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $role = $customer->role === CustomerRole::Broker ? 'وسيط عقاري' : $customer->role->label();
    $falShown = $customer->role === CustomerRole::Broker && $customer->fal_status === FalStatus::Valid && $customer->fal_number;
@endphp

@section('title', $customer->name.' — '.$role)
@section('description', $customer->name.'، '.$role.($customer->city ? ' في '.$customer->city : '').' على زون: '.Format::counted($listings->total(), 'عرض واحد', 'عرضان', 'عروض', 'عرضاً').'.')
@section('canonical', Site::url('users/'.rawurlencode((string) $customer->username)))

@section('content')
<div class="panel">
    <div class="person">
        <span class="avatar" aria-hidden="true">{{ mb_substr($customer->name, 0, 1) }}</span>
        <div class="who">
            <h1>{{ $customer->name }}</h1>
            <span class="muted small">{{ $role }}@if ($customer->city) · {{ $customer->city }}@endif · عضو منذ {{ Format::date($customer->created_at) }}</span>
        </div>
    </div>
    <div class="chips section">
        @if ($falShown)<span class="chip"><x-site.icon name="BadgeCheck" :size="14" class="ok" />رخصة فال <span class="num">{{ $customer->fal_number }}</span></span>@endif
        @if ($customer->nafath_status === NafathStatus::Verified)<span class="chip"><x-site.icon name="ShieldCheck" :size="14" class="ok" />موثّق عبر نفاذ</span>@endif
        @if ($rating['count'] > 0)<span class="chip"><x-site.icon name="Star" :size="14" /><span class="num">{{ $rating['average'] }}</span> ({{ Format::counted($rating['count'], 'تقييم واحد', 'تقييمان', 'تقييمات', 'تقييماً') }})</span>@endif
        @if ($followers > 0)<span class="chip"><x-site.icon name="Users" :size="14" />{{ Format::counted($followers, 'متابع واحد', 'متابعان', 'متابعين', 'متابعاً') }}</span>@endif
    </div>
</div>

@if ($projects->isNotEmpty())
    <section class="section" aria-labelledby="projects-title">
        <div class="section-head"><h2 id="projects-title">المشاريع</h2></div>
        <div class="units">
            @foreach ($projects as $project)
                <a class="unit" href="{{ Site::url('projects/'.$project->id) }}">
                    <span><strong>{{ $project->title }}</strong><br><span class="muted small">{{ $project->neighborhood }}، {{ $project->city }} · {{ $project->delivery->label() }}</span></span>
                    <x-site.icon name="ChevronLeft" :size="18" />
                </a>
            @endforeach
        </div>
    </section>
@endif

<section class="section" aria-labelledby="listings-title">
    <div class="section-head"><h2 id="listings-title">العروض</h2><span class="muted small">{{ Format::counted($listings->total(), 'عرض واحد', 'عرضان', 'عروض', 'عرضاً') }}</span></div>
    @if ($listings->isEmpty())
        <div class="empty"><p>لا عروض منشورة الآن.</p></div>
    @else
        <div class="grid">
            @foreach ($listings as $listing)
                @include('site.partials.listing-card', ['listing' => $listing])
            @endforeach
        </div>
        @if ($listings->hasPages())
            <nav class="pager" aria-label="الصفحات">
                @if (! $listings->onFirstPage())<a href="{{ $listings->previousPageUrl() }}" rel="prev">السابقة</a>@endif
                <span aria-current="page"><span class="num">{{ $listings->currentPage() }}</span></span>
                @if ($listings->hasMorePages())<a href="{{ $listings->nextPageUrl() }}" rel="next">التالية</a>@endif
            </nav>
        @endif
    @endif
</section>
@endsection
