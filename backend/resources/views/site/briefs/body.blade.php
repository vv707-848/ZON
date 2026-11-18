{{-- A market brief's content: on the website (show; its styles in site.css, the website allowing no inline style) and in
     the panel (ViewMarketBrief, $inline: the same styles inline). --}}
@php
    use App\Enums\PropertyType;
    use App\Support\Settings\Setting;
    use App\Support\Settings\Settings;
    use App\SuperAgent\MarketTemplate;
    $bar = ['demand_map' => 'demand_per_listing', 'budget_bands' => 'demand_share', 'rooms_demand' => 'demand_share', 'demand_trend' => 'total',
        'real_budget_index' => 'budget_to_price', 'sale_prices' => 'sale_to_listed', 'unmet_features' => 'unmet_share'];
    $percent = ['demand_share', 'unmet_share', 'change'];
    $show = function (string $column, mixed $value) use ($percent): string {
        if ($value === null || $value === '') return '—';
        if ($column === 'property_type' && is_string($value)) return PropertyType::tryFrom($value)?->label() ?? $value;
        if (! is_int($value) && ! is_float($value)) return (string) $value;
        if (in_array($column, $percent, true)) return ($column === 'change' && $value > 0 ? '+' : '').rtrim(rtrim(number_format($value * 100, 1), '0'), '.').'٪';
        return is_int($value) ? number_format($value) : rtrim(rtrim(number_format($value, 2), '0'), '.');
    };
@endphp
@if ($inline ?? false)<style>@include('site.briefs.style')</style>@endif
<div class="mb">
    @if ($brief->highlights !== [])
        <h2>أهم الأرقام</h2>
        <ul class="mb-high">@foreach ($brief->highlights as $line)<li>{{ $line }}</li>@endforeach</ul>
    @endif
    @if ($brief->analyst_note)
        <div class="mb-note"><strong>ملاحظة فريق زون:</strong> {{ $brief->analyst_note }}</div>
    @endif

    @foreach ($brief->sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <p class="mb-why">{{ $section['why'] }}</p>
        @if ($section['rows'] === [])
            <p class="mb-empty">لا توجد بيانات كافية في هذه الفترة: لا يُعرض صف يقل عن {{ MarketTemplate::minimumGroup() }} طلبات.</p>
        @else
            @php
                $key = $bar[$section['report']] ?? null;
                $max = $key === null ? 0 : max(array_map(fn ($r) => abs((float) ($r[$key] ?? 0)), $section['rows']));
            @endphp
            <div class="mb-wrap"><table>
                <thead><tr>@foreach ($section['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($section['rows'] as $row)
                    <tr>@foreach ($section['columns'] as $column => $label)
                        <td>{{ $show($column, $row[$column] ?? null) }}@if ($column === $key && $max > 0)<span class="mb-bar mb-w{{ (int) round(abs((float) ($row[$column] ?? 0)) / $max * 10) }}" aria-hidden="true"></span>@endif</td>
                    @endforeach</tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    @endforeach

    <div class="mb-method">
        <strong>المنهجية.</strong>
        من بيانات منصة زون: البحث الدلالي (معايير البحث فقط، بلا نص ولا هوية، والباحث يُعدّ مرة في الشهر)، ورغبات من وافقوا على مشاركتها في التقارير، وطلبات البحث المرفوعة، والعروض المنشورة.
        فترة الطلب: آخر {{ $brief->days }} يوماً حتى {{ $brief->created_at->setTimezone('Asia/Riyadh')->format('Y-m-d') }}، والعروض كما كانت منشورة يومها.
        لا يُعرض صف يقل عن {{ MarketTemplate::minimumGroup() }} طلبات، فلا يُعرف منه شخص. الباحث قد يكون له رغبة وطلب معاً، فأعمدة المصادر لا تُجمع على أنها أشخاص مختلفون.
        هذا التقرير وصف لطلب المستخدمين على المنصة، وليس تقييماً عقارياً ولا استشارة استثمارية.
        @if (($name = Settings::string(Setting::SiteLegalName)) !== '') {{ $name }}.@endif
    </div>
</div>
