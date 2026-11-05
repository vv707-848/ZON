@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('title', 'تسجيل الدخول')
@section('robots', 'noindex')
@section('canonical', Site::url('login'))

@section('content')
<div class="narrow">
    <h1>تسجيل الدخول</h1>
    <p class="muted">بحسابك في تطبيق زون نفسه. الحساب الجديد يُنشأ من التطبيق.</p>
    @if (session('status'))<div class="note note-green section" role="status">{{ session('status') }}</div>@endif
    <form class="panel stack section" method="post" action="{{ Site::url('login') }}">
        @csrf
        @include('site.partials.errors')
        <div class="field">
            <label for="l-login">اسم المستخدم أو الجوال</label>
            <input class="input" id="l-login" name="login" value="{{ old('login') }}" dir="ltr" autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <label for="l-pass">كلمة السر</label>
            <input class="input" id="l-pass" name="password" type="password" dir="ltr" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">دخول</button>
        <p class="links-inline small"><a class="link" href="{{ Site::url('password/forgot') }}">نسيت كلمة السر؟</a></p>
    </form>
</div>
@endsection
