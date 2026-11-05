{{-- Where the app is: a store's link once it is set in «إعدادات التطبيق»; before that, an honest «قريباً». --}}
@php $stores = \App\Support\Site\Site::stores(); @endphp
@if ($stores['ios'] || $stores['android'])
    <div class="actions">
        @if ($stores['ios'])<a class="btn {{ $tone ?? 'btn-primary' }}" href="{{ $stores['ios'] }}" rel="noopener"><x-site.icon name="Smartphone" :size="18" />حمّل من App Store</a>@endif
        @if ($stores['android'])<a class="btn {{ $tone ?? 'btn-primary' }}" href="{{ $stores['android'] }}" rel="noopener"><x-site.icon name="Smartphone" :size="18" />حمّل من Google Play</a>@endif
    </div>
@else
    <p class="small soon">التطبيق قريباً على App Store وGoogle Play.</p>
@endif
