@extends('site.layout')
@php
    use App\Support\Settings\Setting;
    use App\Support\Settings\Settings;
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $developer = trim(Settings::string(Setting::SiteLegalName));
    $grace = Format::counted($graceDays, 'يوم واحد', 'يومين', 'أيام', 'يوماً');
@endphp
@section('title', 'حذف الحساب')
@section('description', 'طلب حذف حسابك في تطبيق زون وبياناته دون الحاجة إلى التطبيق: ما يُحذف، وما يُحفظ ولماذا، والمهلة.')
@section('canonical', Site::url('account/delete'))

@section('content')
<div class="narrow">
    <h1>حذف حسابك في تطبيق زون</h1>
    <p class="muted">من هذه الصفحة تطلب حذف حسابك في تطبيق <strong>زون</strong>@if ($developer !== '') ({{ $developer }})@endif وبياناته، دون الحاجة إلى التطبيق.
        ومن داخل التطبيق: حسابي ← الإعدادات ← «حذف حسابي».</p>

    @if ($done)
        <div class="note note-green section" role="status">
            <strong>{{ $done['already'] ? 'طلب حذف حسابك مسجّل من قبل.' : 'سُجّل طلب حذف حسابك.' }}</strong>
            يُحذف الحساب نهائياً في {{ Format::date(\Illuminate\Support\Carbon::parse($done['purge_at'])) }}.
            عروضك وطلباتك مخفية الآن، وخرجت من التطبيق على كل الأجهزة.
            <br>لإلغاء الطلب قبل ذلك: سجّل الدخول في التطبيق أو في <a class="link" href="{{ Site::url('login') }}">الموقع</a>.
        </div>
    @endif

    <section class="panel section" aria-labelledby="steps-title">
        <h2 id="steps-title">كيف يتم الحذف</h2>
        <ol class="ticks-num">
            <li>أدخل اسم المستخدم أو الجوال، وكلمة السر. نسيتها؟ <a class="link" href="{{ Site::url('password/forgot') }}">استعدها برمز على جوالك</a>.</li>
            <li>أكّد الطلب.</li>
            <li>تُخفى عروضك ومشاريعك وطلباتك فوراً، وتخرج من التطبيق على كل الأجهزة.</li>
            <li>يُحذف الحساب نهائياً بعد {{ $grace }}. وتسجيل الدخول خلال هذه المهلة يلغي الطلب ويعيد ما أُخفي.</li>
        </ol>
        <div class="note note-gold section"><strong>قبل الحذف:</strong> إن كان اشتراكك عبر App Store أو Google Play فألغِ تجديده من إعدادات المتجر، فحذف الحساب لا يوقف التجديد. والمدة الباقية من اشتراك ساري لا تُسترد.</div>
    </section>

    <section class="panel" aria-labelledby="deleted-title">
        <h2 id="deleted-title">ما يُحذف</h2>
        <h3 class="small muted">فور الطلب</h3>
        <ul class="ticks">
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />عروضك ومشاريعك وطلباتك المنشورة: تُخفى عن الجميع وتخرج من البحث، ولا يُنشر منها شيء خلال المهلة.</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />محادثاتك: تختفي عند الطرف الآخر.</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />دخولك على كل الأجهزة.</li>
        </ul>
        <h3 class="small muted section">بعد المهلة ({{ $grace }})</h3>
        <ul class="ticks">
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />اسمك واسم المستخدم ورقم جوالك وكلمة السر ورقم رخصة فال وإعدادات الإشعارات وبصمة جهاز التسجيل: يصير الحساب «حساب محذوف».</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />مكتبك: العملاء وملاحظاتهم، والملاك، والمهام، والمخزون، والرغبات، والملخصات.</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />صور عروضك ومشاريعك ومخزونك، وأرقام التواصل عليها.</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />نص رسائلك في المحادثات، وأسئلتك لمساعد الشات.</li>
            <li><x-site.icon name="Check" :size="16" :stroke="2.4" />المفضلة والمتابعات والحظر.</li>
        </ul>
    </section>

    <section class="panel" aria-labelledby="kept-title">
        <h2 id="kept-title">ما يُحفظ ولماذا</h2>
        <ul class="ticks">
            <li><x-site.icon name="Lock" :size="16" />المدفوعات والفواتير: المدة التي يوجبها النظام.</li>
            <li><x-site.icon name="Lock" :size="16" />عقود الوساطة والتسويق والعمولات المستحقة: التزامات نظامية مستقلة عن الحساب.</li>
            <li><x-site.icon name="Lock" :size="16" />سجل عروضك ومشاريعك وطلباتك (النوع والموقع والسعر والوصف، وما بيع منها): مخفي عن الجميع، بلا صور ولا أرقام تواصل ولا اسمك، لسجل العمولات وإحصاءات السوق.</li>
            <li><x-site.icon name="Lock" :size="16" />سجل الشكاوى والتذاكر: رقمها وتواريخها والإجراء المتخذ، بلا كلامك ورقمك.</li>
            <li><x-site.icon name="Lock" :size="16" />بيانات تدريب الذكاء: منزوعة الهوية أصلاً، ولا تُنسب إليك.</li>
            <li><x-site.icon name="Lock" :size="16" />سجل عمليات الحساب لأغراض الأمان (الدخول، وطلب الحذف): يُمسح تلقائياً بعد سنة.</li>
        </ul>
        @if (array_key_exists('privacy', Site::policies()))
            <p class="small section">التفاصيل في <a class="link" href="{{ Site::url('legal/privacy') }}">سياسة الخصوصية</a>.</p>
        @endif
    </section>

    <form class="panel stack" method="post" action="{{ Site::url('account/delete') }}" aria-labelledby="form-title">
        @csrf
        <h2 id="form-title">طلب الحذف</h2>
        @include('site.partials.errors')
        @if ($customer)
            <p class="muted">أنت داخل بحساب <strong>{{ $customer->name }}</strong>.</p>
        @else
            <div class="field">
                <label for="d-login">اسم المستخدم أو الجوال</label>
                <input class="input" id="d-login" name="login" value="{{ old('login') }}" dir="ltr" autocomplete="username" required>
            </div>
        @endif
        <div class="field">
            <label for="d-pass">كلمة السر</label>
            <input class="input" id="d-pass" name="password" type="password" dir="ltr" autocomplete="current-password" required>
        </div>
        <label class="choice"><input type="checkbox" name="confirm" value="1" required> أفهم أن حسابي يُحذف نهائياً بعد {{ $grace }} إن لم ألغِ الطلب.</label>
        <button class="btn btn-block btn-danger" type="submit">اطلب حذف الحساب</button>
        <p class="hint">لا تستطيع الدخول؟ <a class="link" href="{{ Site::url('complaints') }}?type=question">راسلنا</a> ونتحقق معك.</p>
    </form>
</div>
@endsection
