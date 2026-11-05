@extends('site.layout')
@section('title', $heading)
@section('robots', 'noindex')
@section('content')
<div class="oops">
    <div class="code"><span class="num">{{ $code }}</span></div>
    <h1>{{ $heading }}</h1>
    <p>{{ $text }}</p>
    <a class="btn btn-primary" href="{{ \App\Support\Site\Site::url() }}">الصفحة الرئيسية</a>
    @if ($code === 404)<a class="btn btn-ghost" href="{{ \App\Support\Site\Site::url('listings') }}">تصفّح العروض</a>@endif
</div>
@endsection
