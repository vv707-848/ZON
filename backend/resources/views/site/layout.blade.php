@php
    use App\Support\Site\Site;
    $pageTitle = trim($__env->yieldContent('title'));
    $fullTitle = $pageTitle === '' ? 'زون — منصة تطابق العرض العقاري مع الطلب' : $pageTitle.' — زون';
    $description = trim($__env->yieldContent('description')) ?: 'زون منصة عقارية سعودية: تسجّل رغبتك مرة، والمنصة تطابقها مع العروض المرخّصة من الملاك والوسطاء والمطوّرين.';
    $canonical = trim($__env->yieldContent('canonical')) ?: Site::url(request()->path() === '/' ? '' : request()->path());
    $image = trim($__env->yieldContent('og_image'));
    $appStoreId = Site::appStoreId();
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @hasSection('robots')<meta name="robots" content="@yield('robots')">@endif
    <meta name="theme-color" content="#173124">
    <link rel="icon" href="/site/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/site/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/site/apple-touch-icon.png">
    <link rel="stylesheet" href="{{ Site::asset('site.css') }}">
    {{-- Link previews (WhatsApp needs og:title, og:description and og:url, not empty) --}}
    <meta property="og:site_name" content="زون">
    <meta property="og:locale" content="ar_SA">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="{{ $pageTitle === '' ? 'زون' : $pageTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if ($image !== '')
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta property="og:image" content="{{ Site::url('site/og-default.png') }}">
        <meta name="twitter:card" content="summary">
    @endif
    @if ($appStoreId)
        {{-- Safari's banner: opens this same page in the app where it is installed --}}
        <meta name="apple-itunes-app" content="app-id={{ $appStoreId }}, app-argument={{ $canonical }}">
    @endif
    @stack('head')
</head>
<body>
<a class="skip" href="#main">تخطَّ إلى المحتوى</a>
<header class="header">
    <div class="wrap">
        <a class="brand" href="{{ Site::url() }}" aria-label="زون — الرئيسية"><x-site.logo label="" aria-hidden="true" /><span>زون</span></a>
        <nav class="nav" aria-label="الأقسام">
            <a href="{{ Site::url('listings') }}" @if (request()->is('listings*')) aria-current="page" @endif>العروض</a>
            @if (Route::has('site.packages'))<a href="{{ Site::url('packages') }}" @if (request()->is('packages*')) aria-current="page" @endif>الباقات</a>@endif
            @if (Route::has('site.complaints'))<a href="{{ Site::url('complaints') }}" @if (request()->is('complaints*')) aria-current="page" @endif>الشكاوى</a>@endif
            <a class="wide-only" href="{{ Site::url() }}#app">التطبيق</a>
            @if (Route::has('site.account'))<a href="{{ Site::url('account') }}" @if (request()->is('account*', 'login')) aria-current="page" @endif>حسابي</a>@endif
        </nav>
    </div>
</header>

<main id="main">
    <div class="wrap">
        @yield('content')
    </div>
</main>

@include('site.partials.footer')
</body>
</html>
