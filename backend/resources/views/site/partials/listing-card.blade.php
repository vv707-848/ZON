{{-- A listing card as in the app's feed: cover (card size), featured, price, title, place, the main facts. --}}
@php
    use App\Support\Site\Format;
    $cover = $listing->relationLoaded('media') ? $listing->media->first() : null;
@endphp
<a class="card" href="{{ \App\Support\Site\Site::url('listings/'.$listing->id) }}">
    <div class="card-media">
        @if ($cover)
            <img src="{{ $cover->url('card') }}" alt="{{ $listing->title }}" loading="lazy" decoding="async" width="640" height="480">
        @else
            <div class="noimg"><x-site.icon name="ImageOff" :size="28" /></div>
        @endif
        <div class="badges">
            @if ($listing->isFeatured())<span class="badge badge-gold"><x-site.icon name="Crown" :size="12" :stroke="2.2" />مميز</span>@endif
            @if ($listing->isUrgent())<span class="badge badge-danger">عاجل</span>@endif
            <span class="badge badge-ink">{{ $listing->purpose->label() }}</span>
        </div>
    </div>
    <div class="card-body">
        <div class="card-price">{{ Format::price((float) $listing->price, $listing->purpose) }}</div>
        <h3 class="card-title">{{ $listing->title }}</h3>
        <div class="card-place"><x-site.icon name="MapPin" :size="14" />{{ $listing->neighborhood }}، {{ $listing->city }}</div>
        <div class="chips">
            <span class="chip"><x-site.icon name="Building2" :size="14" />{{ $listing->property_type->label() }}</span>
            @if ($listing->bedrooms !== null)<span class="chip"><x-site.icon name="BedDouble" :size="14" />{{ Format::rooms($listing->bedrooms) }}</span>@endif
            @if ($listing->area_built !== null)<span class="chip"><x-site.icon name="Maximize2" :size="14" />{{ Format::area((float) $listing->area_built) }}</span>
            @elseif ($listing->area_land !== null)<span class="chip"><x-site.icon name="Maximize2" :size="14" />{{ Format::area((float) $listing->area_land) }}</span>@endif
        </div>
    </div>
</a>
