@extends('site.layout')
@php
    use App\Enums\PropertyType;
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $types = implode('، ', array_map(fn ($t) => PropertyType::tryFrom((string) $t)?->label() ?? (string) $t, (array) $request->property_types));
    $hoods = implode('، ', (array) $request->neighborhoods);
    $range = function ($min, $max, callable $format) {
        return match (true) {
            $min !== null && $max !== null => 'من '.$format((float) $min).' إلى '.$format((float) $max),
            $max !== null => 'حتى '.$format((float) $max),
            $min !== null => 'من '.$format((float) $min),
            default => null,
        };
    };
    $budget = $range($request->budget_min, $request->budget_max, fn ($v) => Format::price($v));
    $area = $range($request->area_min, $request->area_max, fn ($v) => Format::area($v));
    $rooms = $range($request->rooms_min, $request->rooms_max, fn ($v) => (string) (int) $v);
    $author = $request->customer->role->label();
@endphp

@section('title', 'طلب '.($types ?: 'عقار').' '.$request->purpose->label().' في '.$request->city)
@section('description', implode(' · ', array_filter(['طلب '.$request->purpose->label(), $types, $request->city.($hoods ? ' — '.$hoods : ''), $budget])))
@section('canonical', Site::url('requests/'.$request->id))
@section('robots', 'noindex, follow')

@section('content')
<div class="detail">
    <div class="panel">
        <div class="chips">
            <span class="badge badge-green">طلب {{ $request->purpose->label() }}</span>
            @if ($request->urgent)<span class="badge badge-danger">عاجل</span>@endif
        </div>
        <h1 class="title-top">يبحث عن {{ $types ?: 'عقار' }} في {{ $request->city }}</h1>
        <p class="muted small">صاحب الطلب: {{ $author }} · {{ Format::date($request->created_at) }}</p>
        <dl class="facts section">
            @if ($hoods)<div class="fact"><dt>الأحياء</dt><dd>{{ $hoods }}</dd></div>@endif
            @if ($budget)<div class="fact"><dt>الميزانية</dt><dd>{{ $budget }}</dd></div>@endif
            @if ($area)<div class="fact"><dt>المساحة</dt><dd>{{ $area }}</dd></div>@endif
            @if ($rooms)<div class="fact"><dt>الغرف</dt><dd>{{ $rooms }}</dd></div>@endif
        </dl>
        @if (trim((string) $request->description) !== '')
            <h2 class="section">التفاصيل</h2>
            <p class="prose">{{ $request->description }}</p>
        @endif
    </div>
    <aside class="aside">
        <div class="cta">
            <h2>عندك عقار يناسب هذا الطلب؟</h2>
            <p>التواصل مع صاحب الطلب للوسطاء والمطوّرين في تطبيق زون، حسب باقتهم.</p>
            @include('site.partials.get-app', ['tone' => 'btn-secondary btn-block'])
        </div>
    </aside>
</div>
@endsection
