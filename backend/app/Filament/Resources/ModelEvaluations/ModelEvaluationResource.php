<?php

namespace App\Filament\Resources\ModelEvaluations;

use App\Filament\Concerns\SuperadminOnly;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ModelEvaluations\Pages\ListModelEvaluations;
use App\Filament\Resources\ModelEvaluations\Pages\ViewModelEvaluation;
use App\Filament\Support\LabView;
use App\Models\ModelEvaluation;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * «مختبر النماذج» (superadmin): every run of the model lab uploaded from the AI node, best first on the same
 * dataset, with the limits a run must meet before its score counts (docs/model-lab.md). The model in production is
 * a setting (LLM_PROFILE · ZON_PROFILE): this page is where it is chosen, with numbers.
 */
class ModelEvaluationResource extends Resource
{
    use SuperadminOnly;

    protected static ?string $model = ModelEvaluation::class;

    protected static ?string $slug = 'model-lab';

    protected static ?string $modelLabel = 'جولة قياس';

    protected static ?string $pluralModelLabel = 'مختبر النماذج';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 4;

    /** The columns of the leaderboard: what users meet first. */
    private const SHOWN = ['matching', 'search_listings', 'search_requests', 'clean_listing', 'chat', 'report_select'];

    public static function canCreate(): bool
    {
        return false;                                       // a run arrives as its report file («رفع نتيجة»)
    }

    public static function canEdit(Model $record): bool
    {
        return false;                                       // a measurement is kept as it was measured
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();                        // a wrong upload goes, audited
    }

    public static function table(Table $table): Table
    {
        $latest = ModelEvaluation::query()->latest('started_at')->value('dataset_digest');
        $columns = [
            TextColumn::make('profile')->label('ملف النموذج')->weight('bold')->description(fn (ModelEvaluation $r) => $r->runtime.' · '.($r->gpu ?? '—')),
            TextColumn::make('overall')->label('الإجمالي')->numeric(3)->sortable()->color(fn (ModelEvaluation $r) => $r->unmetLimits() === [] ? 'success' : 'gray'),
            TextColumn::make('limits')->label('الحدود')->badge()
                ->state(fn (ModelEvaluation $r) => $r->unmetLimits() === [] ? 'يجتازها' : 'لا يجتاز ('.count($r->unmetLimits()).')')
                ->color(fn (ModelEvaluation $r) => $r->unmetLimits() === [] ? 'success' : 'danger')
                ->tooltip(fn (ModelEvaluation $r) => implode("\n", $r->unmetLimits()) ?: null),
        ];
        foreach (self::SHOWN as $agent) {
            $columns[] = TextColumn::make("score_{$agent}")->label(LabView::AGENTS[$agent])
                ->state(fn (ModelEvaluation $r) => $r->score($agent))->numeric(3)->placeholder('—');
        }

        return $table->defaultSort('overall', 'desc')
            ->columns([
                ...$columns,
                TextColumn::make('failed')->label('فاشلة')->numeric()->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('p95')->label('p95 المطابقة (ms)')
                    ->state(fn (ModelEvaluation $r) => $r->agents['matching']['latency_ms']['p95'] ?? null)->numeric()->placeholder('—'),
                TextColumn::make('dataset_digest')->label('الأسئلة')->badge()->color('gray')
                    ->description(fn (ModelEvaluation $r) => number_format($r->dataset_cases).' حالة'),
                TextColumn::make('started_at')->label('القياس')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('dataset_digest')->label('مجموعة الأسئلة')->default($latest)
                    ->options(fn () => ModelEvaluation::query()->latest('started_at')->pluck('dataset_digest', 'dataset_digest')->all()),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الجولة')->columns(3)->schema([
                TextEntry::make('profile')->label('ملف النموذج'),
                TextEntry::make('weights')->label('الأوزان')->copyable(),
                TextEntry::make('runtime')->label('المحرك'),
                TextEntry::make('gpu')->label('البطاقة')->placeholder('—'),
                TextEntry::make('overall')->label('الإجمالي')->numeric(3),
                TextEntry::make('failed')->label('نداءات فاشلة'),
                TextEntry::make('dataset_digest')->label('مجموعة الأسئلة')->formatStateUsing(fn (string $state, ModelEvaluation $record) => $state.' · '.number_format($record->dataset_cases).' حالة'),
                TextEntry::make('started_at')->label('القياس')->dateTime(),
                TextEntry::make('duration_s')->label('المدة (ث)'),
                TextEntry::make('throughput')->label('الإنتاجية')
                    ->formatStateUsing(fn (ModelEvaluation $record) => number_format((float) ($record->throughput['tokens_out_per_s'] ?? 0), 1).' توكن/ث · '.number_format((int) ($record->throughput['calls'] ?? 0)).' نداء'),
                TextEntry::make('uploader.name')->label('رفعه')->placeholder('—'),
                TextEntry::make('notes')->label('ملاحظات')->placeholder('—'),
            ]),
            Section::make('الحدود')->description('الأعلى إجمالاً لا يفوز إلا إذا اجتازها كلها (docs/model-lab.md).')
                ->schema([Html::make(fn (ModelEvaluation $record) => LabView::limits($record))]),
            Section::make('كل وكيل')->schema([Html::make(fn (ModelEvaluation $record) => LabView::agents($record))]),
            Section::make('ما أخطأ فيه')->collapsible()->schema([Html::make(fn (ModelEvaluation $record) => LabView::failures($record))]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListModelEvaluations::route('/'), 'view' => ViewModelEvaluation::route('/{record}')];
    }
}
