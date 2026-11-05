@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('title', 'استعادة كلمة السر')
@section('robots', 'noindex')
@section('canonical', Site::url('password/forgot'))

@section('content')
<div class="narrow">
    <h1>استعادة كلمة السر</h1>
    <p class="muted">نرسل رمزاً إلى جوال حسابك، ثم تختار كلمة سر جديدة.</p>
    <form class="panel stack section" method="post" action="{{ Site::url('password/forgot') }}">
        @csrf
        @include('site.partials.errors')
        <div class="field">
            <label for="f-phone">الجوال</label>
            <input class="input" id="f-phone" name="phone" value="{{ old('phone') }}" dir="ltr" inputmode="tel" placeholder="05XXXXXXXX" autocomplete="tel" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">أرسل الرمز</button>
    </form>
</div>
@endsection
