<?php

namespace App\Filament\Pages;

use App\Enums\PropertyType;
use App\Enums\Region;
use App\Filament\NavigationGroup;
use App\Filament\Support\MarketMap;
use App\Filament\Support\ReportView;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;
use App\SuperAgent\ReportRunner;
use App\SuperAgent\SuperAgent as Agent;
use App\SuperAgent\Templates\CityDemandSupply;
use App\SuperAgent\Templates\RegionDemandSupply;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * «تقارير السوق» (superadmin): the map of demand and supply by region, then the market reports — built only from
 * daily snapshots and wishes whose owners allowed it, each row standing for at least the minimum number of requests
 * (never under 5). A report runs in the background with its analysis, like the SuperAgent's (the page polls); the map
 * reads the same approved templates directly, with no model and no archive.
 *
 * @property-read Schema $form
 */
class MarketReports extends Page
{
    protected static ?string $title = 'تقارير السوق';

    protected static ?string $slug = 'market-reports';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 6;

    private const PERIODS = [7 => '7 أيام', 30 => '30 يوماً', 90 => '90 يوماً', 180 => '180 يوماً', 365 => 'سنة'];

    public ?int $reportId = null;

    /** The map's region (its ISO code), kept in the address so a view can be shared. */
    #[Url(as: 'region')]
    public ?string $region = null;

    /** @var array<string, mixed>|null the map's filters */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        return Staff::isSuperadmin();
    }

    public function mount(): void
    {
        $this->form->fill(['days' => 30]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(2)->components([
            Select::make('days')->label('الفترة')->options(self::PERIODS)->selectablePlaceholder(false)->live(),
            Select::make('property_type')->label('نوع العقار')->options(PropertyType::options())->placeholder('كل الأنواع')->live(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $actions = [];
        foreach (Catalog::MARKET as $class) {
            $template = new $class;
            $actions[] = Action::make($template->name())->label($template->title())->schema(self::fields($template))
                ->action(fn (array $data) => $this->run($template, $data));
        }

        return $schema->components([
            Section::make('خريطة السوق')->description('الطلب لكل عرض في كل منطقة إدارية: طلبات الفترة على أحيائها، مقسومة على متوسط عروضها المنشورة يومياً.')
                ->schema([EmbeddedSchema::make('form'), Html::make(fn () => $this->map())]),
            Text::make('الحد الأدنى لكل صف: '.MarketTemplate::minimumGroup().' طلبات (لا يقل عن 5).'),
            Actions::make($actions),
            Section::make('التقرير')->visible(fn () => $this->reportId !== null)->schema([Html::make(fn () => ReportView::render(ExecReport::query()->findOrFail($this->reportId)))]),
        ]);
    }

    /** A region picked on the map or in its table shows its cities; picked again, it closes. */
    public function pickRegion(string $code): void
    {
        $this->region = $code === $this->region ? null : Region::tryFrom($code)?->value;
    }

    private function map(): HtmlString
    {
        $filters = ['days' => (int) ($this->filters['days'] ?? 30), 'property_type' => $this->filters['property_type'] ?? null];
        $picked = Region::tryFrom((string) $this->region);
        $runner = app(ReportRunner::class);
        $regions = new RegionDemandSupply;
        $cities = new CityDemandSupply;

        return MarketMap::render(
            $runner->run($regions, $regions->checkParams($filters)),
            $picked,
            $picked === null ? null : $runner->run($cities, $cities->checkParams($filters + ['region' => $picked->label()])),
        );
    }

    /** @return list<Component> each parameter of the report as a field */
    private static function fields(MarketTemplate $template): array
    {
        $cities = array_keys(City::catalog());
        $regions = array_map(fn (Region $r) => $r->label(), Region::cases());
        $fields = [];
        foreach ($template->params() as $name => $param) {
            $fields[] = match ($name) {
                'city' => Select::make('city')->label($param->label)->options(array_combine($cities, $cities))->required($param->required)->live(),
                'neighborhood' => Select::make('neighborhood')->label($param->label)
                    ->options(fn (Get $get) => array_combine($names = City::catalog()[(string) $get('city')] ?? [], $names)),
                'region' => Select::make('region')->label($param->label)->options(array_combine($regions, $regions))->required($param->required),
                'property_type' => Select::make('property_type')->label($param->label)->options(PropertyType::options())->required($param->required),
                'days' => Select::make('days')->label('الفترة')->options(self::PERIODS)->default($param->default),
                default => self::number($name, $param),
            };
        }

        return $fields;
    }

    private static function number(string $name, Param $param): TextInput
    {
        return TextInput::make($name)->label($param->label)->integer()->minValue($param->min)->maxValue($param->max)->default($param->default);
    }

    /** @param array<string, mixed> $data */
    private function run(MarketTemplate $template, array $data): void
    {
        try {
            $params = array_intersect_key($data, $template->params());
            $this->reportId = app(Agent::class)->manual(Staff::user(), $template, $params, $template->title(), analyse: true)->id;
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
