<?php

namespace App\Filament\Support;

use App\Enums\Region;
use Illuminate\Support\HtmlString;

/**
 * «خريطة السوق»: the 13 administrative regions coloured by demand per listing (RegionDemandSupply's rows), a legend,
 * and the same numbers as a table. A region is picked by a click on the map or its name in the table.
 *
 * - **Scale:** diverging blue ↔ red around «متوازن» (gray): blue where listings outnumber the demand, red where the
 *   demand outruns them — two steps each side. Each arm mirrors the other's lightness, steps from the reference blue
 *   ramp and red computed in OKLCH at the same lightness; checked per mode (monotone lightness, the light end ≥ 2:1 on
 *   the card, the arms apart under protan and deutan: worst ΔE 13.8 light, 18.8 dark).
 * - **Too few requests** (under the market minimum) is hatched, never a colour: it is not «balanced».
 * - **Geometry:** resources/geo/saudi-regions.json (Natural Earth, public domain; `_tools/saudi_regions.py`).
 * Styles are inline: the panel's compiled CSS only holds the classes Filament itself uses; `.dark` is Filament's.
 */
final class MarketMap
{
    /** Demand per listing at the edges of the five classes. */
    public const EDGES = [0.5, 0.8, 1.25, 2.0];

    /** Each class: its name and its range. */
    public const CLASSES = [
        ['العرض أكثر بكثير', 'أقل من 0.5 طلب لكل عرض'],
        ['العرض أكثر', 'من 0.5 إلى 0.8'],
        ['متوازن', 'من 0.8 إلى 1.25'],
        ['الطلب أكثر', 'من 1.25 إلى 2'],
        ['الطلب أكثر بكثير', '2 فأكثر'],
    ];

    private const STYLE = <<<'CSS'
        .zon-map{--zon-surface:#fff;--zon-ink:#0b0b0b;--zon-muted:#898781;--zon-edge:rgba(11,11,11,.28);
          --zon-k0:#256abf;--zon-k1:#86b6ef;--zon-k2:#f0efec;--zon-k3:#f1968e;--zon-k4:#ba3334;display:flex;flex-direction:column;gap:12px;font-size:14px}
        .dark .zon-map{--zon-surface:#18181b;--zon-ink:#f0efec;--zon-edge:rgba(255,255,255,.22);
          --zon-k0:#5598e7;--zon-k1:#184f95;--zon-k2:#383835;--zon-k3:#902123;--zon-k4:#e66963}
        .zon-map svg{width:100%;max-width:720px;height:auto;align-self:center}
        .zon-map path{stroke:var(--zon-edge);stroke-width:1;vector-effect:non-scaling-stroke;cursor:pointer;outline:none}
        .zon-map path:hover,.zon-map path:focus-visible{stroke:var(--zon-ink);stroke-width:2}
        .zon-map path.zon-on{stroke:var(--zon-ink);stroke-width:3}
        .zon-map .zon-k0{fill:var(--zon-k0)}.zon-map .zon-k1{fill:var(--zon-k1)}.zon-map .zon-k2{fill:var(--zon-k2)}
        .zon-map .zon-k3{fill:var(--zon-k3)}.zon-map .zon-k4{fill:var(--zon-k4)}.zon-map .zon-none{fill:url(#zon-none)}
        .zon-map .zon-none-bg{fill:var(--zon-surface)}.zon-map .zon-none-line{stroke:var(--zon-muted);stroke-width:1.5}
        .zon-map text{fill:var(--zon-ink);font-size:20px;font-weight:600;text-anchor:middle;paint-order:stroke;stroke:var(--zon-surface);stroke-width:4px;pointer-events:none}
        .zon-map .zon-legend{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:center;gap:10px 20px;color:var(--zon-ink);font-size:13px}
        .zon-map .zon-scale{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));width:100%;max-width:640px;text-align:center}
        .zon-map .zon-scale span{display:flex;flex-direction:column;gap:2px;padding-inline:2px}
        .zon-map .zon-scale i{height:10px;border-block:1px solid var(--zon-edge)}
        .zon-map .zon-scale span:first-child i{border-inline-start:1px solid var(--zon-edge);border-start-start-radius:3px;border-end-start-radius:3px}
        .zon-map .zon-scale span:last-child i{border-inline-end:1px solid var(--zon-edge);border-start-end-radius:3px;border-end-end-radius:3px}
        .zon-map .zon-swatch{width:14px;height:14px;border-radius:3px;border:1px solid var(--zon-edge);display:inline-block}
        .zon-map .zon-nodata{display:inline-flex;align-items:center;gap:6px;margin-top:12px}
        .zon-map .zon-muted{color:var(--zon-muted)}
        .zon-map table{width:100%;border-collapse:collapse;font-size:13px}
        .zon-map th,.zon-map td{border:1px solid var(--zon-edge);padding:6px 10px;text-align:start}
        .zon-map th{font-weight:600;white-space:nowrap}
        .zon-map button{text-decoration:underline;font-weight:600}
        CSS;

    /**
     * @param  list<array<string, mixed>>  $rows  region_demand_supply's rows (only the regions with enough requests)
     * @param  ?list<array<string, mixed>>  $cities  city_demand_supply's rows for the picked region
     */
    public static function render(array $rows, ?Region $picked, ?array $cities): HtmlString
    {
        $geo = self::geometry();
        $data = collect($rows)->keyBy('region');
        $named = $data->sortByDesc('requests')->take(3)->keys()->all();     // the three busiest regions carry their name

        $paths = $texts = '';
        foreach ($geo['regions'] as $code => $shape) {
            $region = Region::from($code);
            $row = $data->get($code);
            $class = $row === null ? 'zon-none' : 'zon-k'.self::band((float) $row['demand_per_listing']);
            $class .= $region === $picked ? ' zon-on' : '';
            $paths .= '<path d="'.$shape['d'].'" class="'.$class.'" tabindex="0" wire:click="pickRegion(\''.$code.'\')" wire:keydown.enter="pickRegion(\''.$code.'\')">'
                .'<title>'.e(self::describe($region, $row)).'</title></path>';
            if (in_array($code, $named, true)) {
                $texts .= '<text x="'.$shape['label'][0].'" y="'.$shape['label'][1].'">'.e($region->label()).'</text>';
            }
        }

        $box = implode(' ', $geo['viewBox']);
        $html = '<style>'.self::STYLE.'</style><div class="zon-map">'
            .'<svg viewBox="'.$box.'" role="img" aria-label="خريطة الطلب والعرض في المناطق الإدارية"><defs>'
            .'<pattern id="zon-none" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
            .'<rect width="10" height="10" class="zon-none-bg"/><line x1="0" y1="0" x2="0" y2="10" class="zon-none-line"/></pattern></defs>'
            .$paths.$texts.'</svg>'.self::legend().self::table($rows, $picked);
        if ($picked !== null) {
            $html .= self::cities($picked, $cities ?? []);
        }

        return new HtmlString($html.'</div>');
    }

    /** The class of a demand per listing: 0 (listings far ahead) … 4 (the demand far ahead). */
    public static function band(float $perListing): int
    {
        $band = 0;
        foreach (self::EDGES as $edge) {
            $band += $perListing >= $edge ? 1 : 0;
        }

        return $band;
    }

    /** @return array{viewBox: list<float|int>, regions: array<string, array{d: string, label: array{0: float, 1: float}}>} */
    private static function geometry(): array
    {
        /** @var array{viewBox: list<float|int>, regions: array<string, array{d: string, label: array{0: float, 1: float}}>} */
        return once(fn () => json_decode((string) file_get_contents(resource_path('geo/saudi-regions.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param ?array<string, mixed> $row */
    private static function describe(Region $region, ?array $row): string
    {
        if ($row === null) {
            return $region->label().' — لا بيانات كافية (طلبات أقل من الحد الأدنى)';
        }

        return $region->label().' — '.self::CLASSES[self::band((float) $row['demand_per_listing'])][0]
            .' · الطلب لكل عرض: '.self::number($row['demand_per_listing']).' · الطلبات: '.self::number($row['requests'])
            .' · متوسط العروض: '.self::number($row['avg_listings']);
    }

    /** One continuous scale (its five classes side by side, as they run), and «too few requests» apart from it. */
    private static function legend(): string
    {
        $scale = '';
        foreach (self::CLASSES as $band => [$name, $range]) {
            $scale .= '<span><i style="background:var(--zon-k'.$band.')"></i>'.e($name).'<small class="zon-muted">'.e($range).'</small></span>';
        }
        $hatch = 'background:repeating-linear-gradient(45deg,var(--zon-muted) 0 1.5px,var(--zon-surface) 1.5px 6px)';

        return '<div class="zon-legend"><div class="zon-scale">'.$scale.'</div>'
            .'<span class="zon-nodata"><i class="zon-swatch" style="'.$hatch.'"></i>لا بيانات كافية</span></div>';
    }

    /** @param list<array<string, mixed>> $rows */
    private static function table(array $rows, ?Region $picked): string
    {
        if ($rows === []) {
            return '<p>لا توجد منطقة بطلبات كافية في هذه الفترة.</p>';
        }
        $body = '';
        foreach ($rows as $row) {
            $region = Region::from((string) $row['region']);
            $name = '<button type="button" wire:click="pickRegion(\''.$region->value.'\')"'.($region === $picked ? ' aria-current="true"' : '').'>'.e($region->label()).'</button>';
            $body .= '<tr><td>'.$name.'</td><td>'.self::number($row['cities']).'</td><td>'.self::number($row['requests']).'</td><td>'.self::number($row['avg_listings'])
                .'</td><td>'.self::number($row['demand_per_listing']).'</td><td>'.e(self::CLASSES[self::band((float) $row['demand_per_listing'])][0]).'</td></tr>';
        }

        return '<div style="overflow-x:auto"><table><thead><tr><th>المنطقة</th><th>المدن</th><th>الطلبات</th><th>متوسط العروض</th><th>الطلب لكل عرض</th><th>الحال</th></tr></thead><tbody>'
            .$body.'</tbody></table></div>';
    }

    /** @param list<array<string, mixed>> $cities */
    private static function cities(Region $region, array $cities): string
    {
        $html = '<p><strong>مدن '.e($region->label()).'</strong></p>';
        if ($cities === []) {
            return $html.'<p>لا توجد مدينة بطلبات كافية في هذه المنطقة.</p>';
        }
        $body = '';
        foreach ($cities as $row) {
            $body .= '<tr><td>'.e((string) $row['city']).'</td><td>'.self::number($row['requests']).'</td><td>'.self::number($row['avg_listings'])
                .'</td><td>'.self::number($row['demand_per_listing']).'</td><td>'.e(self::CLASSES[self::band((float) $row['demand_per_listing'])][0]).'</td></tr>';
        }

        return $html.'<div style="overflow-x:auto"><table><thead><tr><th>المدينة</th><th>الطلبات</th><th>متوسط العروض</th><th>الطلب لكل عرض</th><th>الحال</th></tr></thead><tbody>'
            .$body.'</tbody></table></div><p class="zon-muted">والأحياء: تقرير «فجوة الطلب والعرض» للمدينة أدناه.</p>';
    }

    private static function number(mixed $value): string
    {
        return match (true) {
            is_int($value) => number_format($value),
            is_float($value) => rtrim(rtrim(number_format($value, 2), '0'), '.'),
            default => e((string) $value),
        };
    }
}
