@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('title', 'كلمة سر جديدة')
@section('robots', 'noindex')
@section('canonical', Site::url('password/forgot'))

@section('content')
<div class="narrow">
    <h1>كلمة سر جديدة</h1>
    <p class="muted">إن كان للرقم <span class="num">{{ $phone }}</span> حساب في زون، فقد أرسلنا إليه رمزاً.</p>
    <form class="panel stack section" method="post" action="{{ Site::url('password/reset') }}">
        @csrf
        @include('site.partials.errors')
        <div class="field">
            <label for="r-code">الرمز</label>
            <input class="input" id="r-code" name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" required>
        </div>
        <div class="two">
            <div class="field">
                <label for="r-pass">كلمة السر الجديدة (8 أحرف على الأقل)</label>
                <input class="input" id="r-pass" name="password" type="password" dir="ltr" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="r-pass2">أعد كتابتها</label>
                <input class="input" id="r-pass2" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" required>
            </div>
        </div>
        <button class="btn btn-primary btn-block" type="submit">حفظ</button>
        <p class="small"><a class="link" href="{{ Site::url('password/forgot') }}">لم يصل الرمز؟ أعد الإرسال</a></p>
    </form>
</div>
@endsection
