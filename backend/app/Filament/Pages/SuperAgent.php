<?php

namespace App\Filament\Pages;

use App\Enums\PropertyType;
use App\Filament\NavigationGroup;
use App\Filament\Support\ReportView;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\DailyCap;
use App\SuperAgent\SuperAgent as Agent;
use App\SuperAgent\Template;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
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
 * «الوكيل الأعلى» (superadmin only): ask in Arabic, get an approved report with an analysis whose every number comes
 * from its rows — or pick a template yourself (manual, no model). Reports are archived (exec_reports).
 *
 * @property-read Schema $form
 */
class SuperAgent extends Page
{
    protected static ?string $title = 'الوكيل الأعلى';

    protected static ?string $slug = 'super-agent';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 5;

    /** The report on screen (?report=…), so one can be shared with another superadmin. */
    #[Url(as: 'report')]
    public ?int $reportId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Staff::isSuperadmin();
    }

    public function mount(): void
    {
        $this->form->fill(['days' => 30, 'months' => 6, 'limit' => 10]);
    }

    public function form(Schema $schema): Schema
    {
        $templates = collect(Catalog::all())->mapWithKeys(fn (Template $t, string $name) => [$name => $t->title()])->all();

        return $schema->statePath('data')->components([
            Textarea::make('question')->label('سؤالك')->rows(2)->maxLength(500)->placeholder('مثال: أين يزيد الطلب على العرض في الرياض هذا الشهر؟'),
            Section::make('الوضع اليدوي (بلا نموذج)')->collapsed()->columns(3)->schema([
                Select::make('template')->label('التقرير')->options($templates)->live()->columnSpanFull(),
                Select::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog())))
                    ->visible(fn (Get $get) => $this->uses($get('template'), 'city')),
                Select::make('property_type')->label('النوع')->options(PropertyType::options())->visible(fn (Get $get) => $this->uses($get('template'), 'property_type')),
                TextInput::make('days')->label('الأيام')->integer()->minValue(1)->maxValue(365)->visible(fn (Get $get) => $this->uses($get('template'), 'days')),
                TextInput::make('months')->label('الأشهر')->integer()->minValue(1)->maxValue(24)->visible(fn (Get $get) => $this->uses($get('template'), 'months')),
                TextInput::make('limit')->label('الصفوف')->integer()->minValue(1)->maxValue(50)->visible(fn (Get $get) => $this->uses($get('template'), 'limit')),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn () => 'المتبقي اليوم: '.DailyCap::left(Staff::user()).' تقرير'),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('ask')->footer([Actions::make([
                Action::make('ask')->label('اسأل')->submit('ask'),
                Action::make('manual')->label('تشغيل التقرير المختار')->color('gray')->action('manual'),
            ])]),
            Section::make('التقرير')->visible(fn () => $this->report() !== null)->schema([Html::make(fn () => ReportView::render($this->report()))]),
            Section::make('آخر التقارير')->collapsed()->schema([Html::make(fn () => $this->history())]),
        ]);
    }

    public function ask(): void
    {
        $question = trim((string) ($this->form->getState()['question'] ?? ''));
        if ($question === '') {
            Notification::make()->title('اكتب سؤالك')->warning()->send();

            return;
        }
        $this->runSafely(fn () => app(Agent::class)->ask(Staff::user(), $question));
    }

    public function manual(): void
    {
        $state = $this->form->getState();
        $template = Catalog::find((string) ($state['template'] ?? ''));
        if ($template === null) {
            Notification::make()->title('اختر التقرير')->warning()->send();

            return;
        }
        $params = array_intersect_key($state, $template->params());
        $this->runSafely(fn () => app(Agent::class)->run(Staff::user(), $template, $params, trim((string) ($state['question'] ?? '')) ?: $template->title(), analyse: false));
    }

    private function runSafely(\Closure $run): void
    {
        try {
            $report = $run();
        } catch (Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? 'معامل غير مقبول: '.$e->getMessage() : $e->getMessage())->danger()->send();

            return;
        }
        $this->reportId = $report->id;
        if ($report->status === 'failed') {
            Notification::make()->title('لم يُفهم السؤال ضمن التقارير المعتمدة — جرّب صياغة أخرى أو الوضع اليدوي')->warning()->send();
        }
    }

    private function report(): ?ExecReport
    {
        return $this->reportId === null ? null : ExecReport::query()->where('staff_id', Staff::user()->id)->find($this->reportId);
    }

    private function uses(?string $template, string $param): bool
    {
        return array_key_exists($param, Catalog::find((string) $template)?->params() ?? []);
    }

    private function history(): HtmlString
    {
        $rows = ExecReport::query()->where('staff_id', Staff::user()->id)->latest('id')->limit(20)->get()
            ->map(fn (ExecReport $r) => '<li><a class="underline" href="?report='.$r->id.'">'.e($r->created_at?->format('Y-m-d H:i').' — '.$r->question).'</a> ('.e($r->status).')</li>');

        return new HtmlString($rows->isEmpty() ? '<p>لا تقارير بعد.</p>' : '<ul class="space-y-1">'.$rows->implode('').'</ul>');
    }
}
