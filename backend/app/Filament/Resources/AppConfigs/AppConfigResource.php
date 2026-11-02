<?php

namespace App\Filament\Resources\AppConfigs;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AppConfigs\Pages\EditAppConfig;
use App\Filament\Resources\AppConfigs\Pages\ListAppConfigs;
use App\Models\AppConfig;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «إصدار التطبيق ووضع الصيانة» per platform: under min_version the app must update (426); maintenance answers 503
 * to everyone but test accounts, until its end time.
 */
class AppConfigResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = AppConfig::class;

    protected static ?string $slug = 'app-versions';

    protected static ?string $modelLabel = 'منصة';

    protected static ?string $pluralModelLabel = 'إصدار التطبيق والصيانة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?int $navigationSort = 2;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageAppConfig;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('min_version')->label('أقل إصدار مسموح')->required()->regex('/^\d+\.\d+\.\d+$/'),
            TextInput::make('latest_version')->label('أحدث إصدار')->required()->regex('/^\d+\.\d+\.\d+$/'),
            TextInput::make('store_url')->label('رابط المتجر')->url()->required()->columnSpanFull(),
            Toggle::make('maintenance_mode')->label('وضع الصيانة')->live(),
            DateTimePicker::make('maintenance_until')->label('حتى (اختياري)'),
            Textarea::make('maintenance_message')->label('رسالة الصيانة')->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('platform')->label('المنصة')->formatStateUsing(fn (string $state) => $state === 'ios' ? 'iOS' : 'Android'),
            TextColumn::make('min_version')->label('الأقل'),
            TextColumn::make('latest_version')->label('الأحدث'),
            IconColumn::make('maintenance_mode')->label('صيانة')->boolean(),
            TextColumn::make('maintenance_until')->label('حتى')->dateTime()->placeholder('—'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAppConfigs::route('/'), 'edit' => EditAppConfig::route('/{record}/edit')];
    }
}
