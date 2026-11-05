@extends('site.layout')
@php use App\Enums\TicketType; use App\Support\Site\Site; @endphp
@section('title', 'الشكاوى والاقتراحات')
@section('description', 'قدّم شكوى أو اقتراحاً أو استفساراً لمنصة زون، واحصل على رقم قيد لمتابعة الإجراءات ومدة المعالجة.')
@section('canonical', Site::url('complaints'))

@section('content')
<div class="narrow">
    <h1>الشكاوى والاقتراحات</h1>
    <p class="muted">يصلك فوراً <strong>رقم قيد</strong> وتاريخ لشكواك، و<strong>رابط خاص</strong> تتابع فيه الإجراءات التي تمت بشأنها.
        نرد خلال <strong>{{ \App\Support\Site\Format::counted($responseHours, 'ساعة', 'ساعتين', 'ساعات', 'ساعة') }}</strong>.</p>
    <p class="small section"><a class="link" href="{{ Site::url('complaints/track') }}">لديك شكوى سابقة؟ تابعها برقمها</a></p>

    <form class="panel stack section" method="post" action="{{ Site::url('complaints') }}" novalidate>
        @csrf
        <input type="hidden" name="started_at" value="{{ $startedAt }}">
        {{-- Left empty by people; bots fill every field --}}
        <div class="visually-hidden" aria-hidden="true"><label>لا تملأ هذا الحقل <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        @include('site.partials.errors')

        <fieldset class="field">
            <legend class="label">نوع الرسالة</legend>
            <div class="choices">
                @foreach (TicketType::forCustomers() as $value)
                    <label class="choice"><input type="radio" name="type" value="{{ $value }}" @checked(old('type', $type) === $value)> {{ TicketType::from($value)->label() }}</label>
                @endforeach
            </div>
        </fieldset>
        <div class="two">
            <div class="field">
                <label for="c-name">الاسم</label>
                <input class="input" id="c-name" name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name" required @error('name') aria-invalid="true" @enderror>
                @error('name')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="c-phone">الجوال</label>
                <input class="input" id="c-phone" name="phone" value="{{ old('phone') }}" inputmode="tel" dir="ltr" placeholder="05XXXXXXXX" autocomplete="tel" required @error('phone') aria-invalid="true" @enderror>
                @error('phone')<span class="error">{{ $message }}</span>@enderror
                <span class="hint">نتصل بك عليه إن احتجنا، ويُطلب مع رقم الشكوى لمتابعتها.</span>
            </div>
        </div>
        <div class="field">
            <label for="c-listing">رقم العرض أو رابطه (إن كانت الشكوى عن إعلان)</label>
            <input class="input" id="c-listing" name="listing" value="{{ old('listing', $listing ? Site::url('listings/'.$listing->id) : '') }}" dir="ltr" @error('listing') aria-invalid="true" @enderror>
            @error('listing')<span class="error">{{ $message }}</span>@enderror
            @if ($listing)<span class="hint">عن: {{ $listing->title }}</span>@endif
        </div>
        <div class="field">
            <label for="c-subject">الموضوع (اختياري)</label>
            <input class="input" id="c-subject" name="subject" value="{{ old('subject') }}" maxlength="160">
        </div>
        <div class="field">
            <label for="c-body">التفاصيل</label>
            <textarea class="input" id="c-body" name="body" rows="6" maxlength="2000" required @error('body') aria-invalid="true" @enderror>{{ old('body') }}</textarea>
            @error('body')<span class="error">{{ $message }}</span>@enderror
        </div>
        <button class="btn btn-primary btn-block" type="submit">إرسال</button>
        <p class="hint">بإرسالك تقبل استخدام بياناتك لمعالجة رسالتك والتواصل معك بشأنها.</p>
    </form>
</div>
@endsection
