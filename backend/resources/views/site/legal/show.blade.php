@extends('site.layout')
@php use App\Support\Site\Format; use App\Support\Site\Site; @endphp
@section('title', $page->title)
@section('description', $page->title.' لمنصة زون — النسخة '.$page->version.'.')
@section('canonical', Site::url('legal/'.$page->slug))

@section('content')
<nav aria-label="مسار الصفحة"><ol class="crumbs">
    <li><a href="{{ Site::url() }}">الرئيسية</a></li>
    <li><a href="{{ Site::url('legal') }}">السياسات والشروط</a></li>
</ol></nav>
<article class="doc">
    <h1>{{ $page->title }}</h1>
    <p class="meta">النسخة <span class="num">{{ $page->version }}</span> · سارية منذ {{ Format::date($page->published_at) }}</p>
    <div class="body">{!! $html !!}</div>
</article>
@endsection
