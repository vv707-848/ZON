<?php

namespace App\Filament\Resources\SearchRequests;

use App\Actions\Moderation\ModerateContent;
use App\Enums\RequestStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\SearchRequests\Pages\ListSearchRequests;
use App\Filament\Resources\SearchRequests\Pages\ViewSearchRequest;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\SearchRequest;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** «الطلبات»: search requests (normal and urgent), and how many area brokers each one reached. */
class SearchRequestResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = SearchRequest::class;

    protected static ?string $slug = 'requests';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?int $navigationSort = 3;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewRequests;
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
            Section::make('الطلب')->columns(4)->schema([
                Columns::enumEntry('status', 'الحالة'),
                Columns::enumEntry('purpose', 'الغرض'),
                TextEntry::make('urgent')->label('عاجل')->formatStateUsing(fn (bool $state) => $state ? 'نعم' : 'لا'),
                TextEntry::make('customer.name')->label('صاحب الطلب')->url(fn (SearchRequest $r) => CustomerResource::getUrl('view', ['record' => $r->customer_id])),
                TextEntry::make('city')->label('المدينة'),
                TextEntry::make('neighborhoods')->label('الأحياء')->badge(),
                TextEntry::make('property_types')->label('الأنواع')->badge(),
                TextEntry::make('budget')->label('الميزانية')->state(fn (SearchRequest $r) => number_format((float) $r->budget_min).' – '.number_format((float) $r->budget_max)),
                TextEntry::make('description')->label('الوصف')->columnSpanFull()->placeholder('—'),
                TextEntry::make('reached')->label('وسطاء المنطقة المبلَّغون')->state(fn (SearchRequest $r) => DB::table('notifications')
                    ->where('type', 'request')->where('data->key', "request:{$r->id}")->count()),
                TextEntry::make('created_at')->label('التاريخ')->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->columns([
                TextColumn::make('id')->label('#'),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                IconColumn::make('urgent')->label('عاجل')->boolean(),
                TextColumn::make('city')->label('المدينة'),
                TextColumn::make('neighborhoods')->label('الأحياء')->badge()->limitList(3),
                TextColumn::make('property_types')->label('الأنواع')->badge()->limitList(2),
                TextColumn::make('budget_max')->label('حتى')->numeric()->placeholder('—'),
                TextColumn::make('customer.name')->label('صاحب الطلب')->searchable()->limit(20),
                TextColumn::make('created_at')->label('التاريخ')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(RequestStatus::options()),
                SelectFilter::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                TernaryFilter::make('urgent')->label('عاجل'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> */
    public static function moderationActions(): array
    {
        $move = fn (string $name, string $label, string $color, RequestStatus $to, RequestStatus $from) => Action::make($name)->label($label)->color($color)
            ->visible(fn (SearchRequest $r) => Staff::can(Permission::ModerateRequests) && $r->status === $from)
            ->schema([Textarea::make('reason')->label('السبب')->required()->maxLength(500)])->requiresConfirmation()
            ->action(fn (SearchRequest $record, array $data) => app(ModerateContent::class)->request($record, $to, $data['reason']));

        return [
            $move('hide', 'إخفاء', 'danger', RequestStatus::Hidden, RequestStatus::Published),
            $move('restore', 'إرجاع', 'success', RequestStatus::Published, RequestStatus::Hidden),
            $move('close', 'إغلاق', 'gray', RequestStatus::Closed, RequestStatus::Published),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListSearchRequests::route('/'), 'view' => ViewSearchRequest::route('/{record}')];
    }
}
