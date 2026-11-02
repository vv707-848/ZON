<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Support\ReportView;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\SuperAgent as Agent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * «تقارير السوق» (superadmin): the four market reports, built only from daily snapshots and wishes whose owners
 * allowed it, each row standing for at least the minimum number of requests (never under 5).
 */
class MarketReports extends Page
{
    protected static ?string $title = 'تقارير السوق';

    protected static ?string $slug = 'market-reports';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 6;

    public ?int $reportId = null;

    public static function canAccess(): bool
    {
        return Staff::isSuperadmin();
    }

    public function content(Schema $schema): Schema
    {
        $cities = array_combine(array_keys(City::catalog()), array_keys(City::catalog()));
        $actions = [];
        foreach (Catalog::MARKET as $class) {
            $template = new $class;
            $actions[] = Action::make($template->name())->label($template->title())
                ->schema([Select::make('city')->label('المدينة')->options($cities)->required(), Select::make('days')->label('الفترة')->options([7 => '7 أيام', 30 => '30 يوماً', 90 => '90 يوماً'])->default(30)])
                ->action(fn (array $data) => $this->run($template, $data));
        }

        return $schema->components([
            Text::make('الحد الأدنى لكل صف: '.MarketTemplate::minimumGroup().' طلبات (لا يقل عن 5).'),
            Actions::make($actions),
            Section::make('التقرير')->visible(fn () => $this->reportId !== null)->schema([Html::make(fn () => ReportView::render(ExecReport::query()->findOrFail($this->reportId)))]),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function run(MarketTemplate $template, array $data): void
    {
        try {
            $params = array_intersect_key($data, $template->params());
            $this->reportId = app(Agent::class)->run(Staff::user(), $template, $params, $template->title(), analyse: true)->id;
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
