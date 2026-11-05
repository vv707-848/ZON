@extends('site.layout')
@section('title', 'السياسات والشروط')
@section('description', 'سياسات منصة زون وشروط استخدامها، كما يعتمدها صاحب المنصة.')

@section('content')
<h1>السياسات والشروط</h1>
@if ($policies === [])
    <div class="empty section"><x-site.icon name="FileText" :size="32" /><p>تُنشر السياسات هنا عند اعتمادها.</p></div>
@else
    <ul class="list-links section">
        @foreach ($policies as $slug => $title)
            <li><a href="{{ \App\Support\Site\Site::url('legal/'.$slug) }}"><x-site.icon name="FileText" :size="20" />{{ $title }}<x-site.icon name="ChevronLeft" :size="18" /></a></li>
        @endforeach
    </ul>
@endif
@endsection
