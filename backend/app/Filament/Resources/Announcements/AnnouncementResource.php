<?php

namespace App\Filament\Resources\Announcements;

use App\Enums\CustomerRole;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\City;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** «الإشعارات العامة»: a notice (bell + push) to everyone, a role and/or a city; sent once, recipients counted. */
class AnnouncementResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Announcement::class;

    protected static ?string $modelLabel = 'إشعار عام';

    protected static ?string $pluralModelLabel = 'الإشعارات العامة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    protected static function viewPermission(): Permission
    {
        return Permission::SendAnnouncements;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('العنوان')->required()->maxLength(120),
            Select::make('audience')->label('إلى')->required()->default('all')->options(['all' => 'الجميع'] + CustomerRole::options()),
            Select::make('city')->label('المدينة (اختياري)')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
            TextInput::make('target')->label('يفتح في التطبيق (اختياري)')->placeholder('/account/plans')->regex('#^/[A-Za-z0-9/_\-?=&%]*$#')->maxLength(120),
            Textarea::make('body')->label('النص')->required()->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->columns([
                TextColumn::make('title')->label('العنوان'),
                TextColumn::make('audience')->label('إلى')->formatStateUsing(fn (string $state) => $state === 'all' ? 'الجميع' : CustomerRole::from($state)->label()),
                TextColumn::make('city')->label('المدينة')->placeholder('كل المدن'),
                TextColumn::make('recipients')->label('المستلمون'),
                TextColumn::make('sent_at')->label('أُرسل')->dateTime()->placeholder('قيد الإرسال'),
                TextColumn::make('creator.name')->label('بواسطة'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/'), 'create' => CreateAnnouncement::route('/create')];
    }
}
