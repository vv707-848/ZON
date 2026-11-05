@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('title', 'متابعة شكوى')
@section('robots', 'noindex')
@section('canonical', Site::url('complaints/track'))

@section('content')
<div class="narrow">
    <h1>متابعة شكوى</h1>
    <p class="muted">أدخل رقم القيد الذي وصلك والجوال الذي قدّمت منه الشكوى. مستخدمو التطبيق يتابعون رسائلهم فيه.</p>
    <form class="panel stack section" method="post" action="{{ Site::url('complaints/track') }}">
        @csrf
        @include('site.partials.errors')
        <div class="field">
            <label for="t-ref">رقم القيد</label>
            <input class="input" id="t-ref" name="reference" value="{{ old('reference') }}" dir="ltr" placeholder="ZT-261105-XXXXX" required>
        </div>
        <div class="field">
            <label for="t-phone">الجوال</label>
            <input class="input" id="t-phone" name="phone" value="{{ old('phone') }}" dir="ltr" inputmode="tel" placeholder="05XXXXXXXX" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">عرض الشكوى</button>
    </form>
    <p class="small section"><a class="link" href="{{ Site::url('complaints') }}">تقديم شكوى جديدة</a></p>
</div>
@endsection
