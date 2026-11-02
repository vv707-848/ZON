<?php

namespace App\Filament\Resources\ContentReports;

use App\Actions\Moderation\HandleReport;
use App\Enums\ReportAction;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ContentReports\Pages\ListContentReports;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\SearchRequests\SearchRequestResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\ContentReport;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** «البلاغات»: one per reporter and target; enough reporters already hid the target until a decision here. */
class ContentReportResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = ContentReport::class;

    protected static ?string $slug = 'reports';

    protected static ?string $modelLabel = 'بلاغ';

    protected static ?string $pluralModelLabel = 'البلاغات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Moderation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $open = ContentReport::query()->whereIn('status', [ReportStatus::New, ReportStatus::Reviewing])->count();

        return $open > 0 ? (string) $open : null;
    }

    protected static function viewPermission(): Permission
    {
        return Permission::HandleReports;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['reporter', 'target']))
            ->columns([
                TextColumn::make('target_type')->label('الهدف')->formatStateUsing(fn (string $state, ContentReport $r) => [
                    'listing' => 'عرض', 'project' => 'مشروع', 'request' => 'طلب', 'customer' => 'حساب', 'message' => 'رسالة', 'rating' => 'تقييم',
                ][$state]." #{$r->target_id}")
                    ->url(fn (ContentReport $r) => match ($r->target_type) {
                        'listing' => ListingResource::getUrl('view', ['record' => $r->target_id]),
                        'project' => ProjectResource::getUrl('view', ['record' => $r->target_id]),
                        'request' => SearchRequestResource::getUrl('view', ['record' => $r->target_id]),
                        'customer' => CustomerResource::getUrl('view', ['record' => $r->target_id]),
                        default => null,
                    }),
                TextColumn::make('reporters')->label('المبلّغون')->state(fn (ContentReport $r) => ContentReport::query()
                    ->where('target_type', $r->target_type)->where('target_id', $r->target_id)->count()),
                Columns::enum('reason', 'السبب'),
                TextColumn::make('details')->label('التفاصيل')->limit(50)->tooltip(fn (ContentReport $r) => $r->details),
                TextColumn::make('reporter.name')->label('المبلّغ')->limit(20),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                Columns::enum('action_taken', 'الإجراء')->toggleable(),
                TextColumn::make('created_at')->label('التاريخ')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(ReportStatus::options())->default(ReportStatus::New->value),
                SelectFilter::make('reason')->label('السبب')->options(ReportReason::options()),
                SelectFilter::make('target_type')->label('الهدف')->options(['listing' => 'عرض', 'project' => 'مشروع', 'request' => 'طلب', 'customer' => 'حساب', 'message' => 'رسالة', 'rating' => 'تقييم']),
            ])
            ->recordActions([
                Action::make('decide')->label('القرار')->icon('heroicon-o-scale')
                    ->visible(fn (ContentReport $r) => in_array($r->status, [ReportStatus::New, ReportStatus::Reviewing], true) && Staff::can(Permission::HandleReports))
                    ->schema([
                        // Word keys, not '1'/'0': PHP turns numeric array keys into integers, and the choice is then never === '1'.
                        Radio::make('decision')->label('البلاغ')->options(['accept' => 'صحيح', 'reject' => 'غير صحيح (يُعاد ما أخفته البلاغات آلياً)'])->required()->live(),
                        Select::make('action')->label('الإجراء')->options(ReportAction::options())->required(fn (Get $get) => $get('decision') === 'accept')
                            ->visible(fn (Get $get) => $get('decision') === 'accept'),
                        Textarea::make('note')->label('ملاحظة القرار')->required()->maxLength(1000),
                    ])
                    ->action(fn (ContentReport $record, array $data) => app(HandleReport::class)($record, $data['decision'] === 'accept',
                        ReportAction::from($data['action'] ?? ReportAction::Ignored->value), $data['note'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListContentReports::route('/')];
    }
}
