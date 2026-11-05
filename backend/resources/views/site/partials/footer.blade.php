@php
    use App\Support\Site\Site;
    $identity = Site::identity();
    $contact = Site::contact();
    $policies = Site::policies();
@endphp
<footer class="footer">
    <div class="wrap">
        <div class="footer-grid">
            <div>
                <a class="brand" href="{{ Site::url() }}"><x-site.logo label="" aria-hidden="true" /><span>زون</span></a>
                <p>منصة عقارية سعودية تطابق العرض مع الطلب: الباحث يسجّل رغبته مرة، والعروض المرخّصة تصله.</p>
                @if ($identity !== [])
                    <dl class="small identity">
                        @foreach ($identity as $label => $value)
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                @endif
            </div>
            <nav aria-label="روابط الموقع">
                <h2>المنصة</h2>
                <ul>
                    <li><a href="{{ Site::url('listings') }}">العروض</a></li>
                    @if (Route::has('site.packages'))<li><a href="{{ Site::url('packages') }}">الباقات والأسعار</a></li>@endif
                    @if (Route::has('site.complaints'))<li><a href="{{ Site::url('complaints') }}">تقديم شكوى ومتابعتها</a></li>@endif
                    @if (Route::has('site.account.delete'))<li><a href="{{ Site::url('account/delete') }}">حذف الحساب</a></li>@endif
                    @if ($contact['email'])<li><a href="mailto:{{ $contact['email'] }}"><span class="num">{{ $contact['email'] }}</span></a></li>@endif
                    @if ($contact['phone'])<li><a href="tel:{{ preg_replace('/[^\d+]/', '', $contact['phone']) }}"><span class="num">{{ $contact['phone'] }}</span></a></li>@endif
                </ul>
            </nav>
            <nav aria-label="السياسات">
                <h2>السياسات</h2>
                @if ($policies === [])
                    <p class="small">تُنشر هنا عند اعتمادها.</p>
                @else
                    <ul>
                        @foreach ($policies as $slug => $title)
                            <li><a href="{{ Site::url('legal/'.$slug) }}">{{ $title }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </nav>
        </div>
        <div class="footer-bottom">© <span class="num">{{ now('Asia/Riyadh')->year }}</span> زون</div>
    </div>
</footer>
