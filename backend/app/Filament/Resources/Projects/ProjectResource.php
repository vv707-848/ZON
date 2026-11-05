<?php

namespace App\Filament\Resources\Projects;

use App\Actions\Moderation\ModerateContent;
use App\Enums\Delivery;
use App\Enums\ListingStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\Concerns\SearchesByIdentifier;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\UnitsRelationManager;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\Project;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** «المشاريع ووحداتها»: developers' projects, their unit models (available, sold), wording and licences. */
class ProjectResource extends Resource
{
    use AuthorizesByPermission, SearchesByIdentifier;

    protected static ?string $model = Project::class;

    protected static ?string $modelLabel = 'مشروع';

    protected static ?string $pluralModelLabel = 'المشاريع';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 2;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewListings;
    }

    protected static function identifierMatches(Builder $query, string $search): void
    {
        if (preg_match('/^\d{1,12}$/', $search) === 1) {
            $query->orWhereKey((int) $search);
        }
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('developer');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Project);

        return ['الرقم' => (string) $record->id, 'الحالة' => $record->status->label(), 'المكان' => "{$record->city} · {$record->neighborhood}", 'المطوّر' => (string) $record->developer?->name];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المشروع')->columns(4)->schema([
                TextEntry::make('title')->label('العنوان')->columnSpan(2),
                Columns::enumEntry('status', 'الحالة'),
                Columns::enumEntry('delivery', 'التسليم'),
                TextEntry::make('city')->label('المدينة'),
                TextEntry::make('neighborhood')->label('الحي'),
                Columns::enumEntry('property_type', 'النوع'),
                TextEntry::make('total_units')->label('عدد الوحدات'),
                TextEntry::make('developer.name')->label('المطوّر')->url(fn (Project $r) => CustomerResource::getUrl('view', ['record' => $r->developer_id])),
                Columns::enumEntry('text_review', 'الصياغة'),
                TextEntry::make('views_count')->label('المشاهدات'),
                TextEntry::make('published_at')->label('النشر')->dateTime()->placeholder('—'),
            ]),
            Section::make('النصوص')->schema([
                TextEntry::make('desc_raw')->label('نص المطوّر'),
                TextEntry::make('desc_suggested')->label('مقترح الذكاء')->placeholder('—'),
                TextEntry::make('desc_clean')->label('المعتمد')->placeholder('النص الأصلي'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('developer')->withCount('units')->withSum('units', 'available_count'))
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->limit(35),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                Columns::enum('delivery', 'التسليم'),
                TextColumn::make('neighborhood')->label('الحي')->description(fn (Project $r) => $r->city),
                TextColumn::make('developer.name')->label('المطوّر')->limit(20),
                TextColumn::make('units_count')->label('النماذج'),
                TextColumn::make('units_sum_available_count')->label('المتاح')->placeholder('0'),
                TextColumn::make('published_at')->label('النشر')->date(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')   // a project has no licence of its own: its units do
                    ->options(collect(ListingStatus::options())->except(ListingStatus::LicenceExpired->value)->all()),
                SelectFilter::make('delivery')->label('التسليم')->options(Delivery::options()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> */
    public static function moderationActions(): array
    {
        $move = fn (string $name, string $label, string $color, ListingStatus $to) => Action::make($name)->label($label)->color($color)
            ->visible(fn (Project $record) => Staff::can(Permission::ModerateListings) && $record->status->canBecome($to))
            ->schema([Textarea::make('reason')->label('السبب')->required()->maxLength(500)])->requiresConfirmation()
            ->action(fn (Project $record, array $data) => app(ModerateContent::class)->project($record, $to, $data['reason']));

        return [
            $move('hide', 'إخفاء', 'danger', ListingStatus::Hidden),
            $move('restore', 'إرجاع للنشر', 'success', ListingStatus::Published),
            $move('reject', 'رفض', 'danger', ListingStatus::Rejected),
        ];
    }

    public static function getRelations(): array
    {
        return [UnitsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListProjects::route('/'), 'view' => ViewProject::route('/{record}')];
    }
}
