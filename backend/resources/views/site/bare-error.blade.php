{{-- An error page that needs nothing but itself: no database (a 500 may be the database), no settings. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} — زون</title>
    <link rel="icon" href="/site/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/site/site.css">
</head>
<body>
<main id="main"><div class="wrap"><div class="oops">
    <div class="code"><span class="num">{{ $code }}</span></div>
    <h1>{{ $heading }}</h1>
    <p>{{ $text }}</p>
    <a class="btn btn-primary" href="/">الصفحة الرئيسية</a>
</div></div></main>
</body>
</html>
