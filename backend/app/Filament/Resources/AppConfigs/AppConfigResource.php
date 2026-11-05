<?php

namespace App\Filament\Resources\AppConfigs;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AppConfigs\Pages\EditAppConfig;
use App\Filament\Resources\AppConfigs\Pages\ListAppConfigs;
use App\Models\AppConfig;
use App\Support\Permissions\Permission;
use App\Support\Site\Site;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
            // A forced update sends the user to the store: no link, no forced update.
            TextInput::make('min_version')->label('أقل إصدار مسموح')->required()->regex('/^\d+\.\d+\.\d+$/')
                ->rule(fn (?AppConfig $record, Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($record, $get) {
                    if ($record !== null && $value !== $record->min_version && blank($get('store_url'))) {
                        $fail('أضف رابط المتجر قبل رفع أقل إصدار: التحديث الإجباري يفتح صفحة المتجر.');
                    }
                }),
            TextInput::make('latest_version')->label('أحدث إصدار')->required()->regex('/^\d+\.\d+\.\d+$/'),
            // Empty until the app is published there; the website shows a store's badge once its real link is set.
            TextInput::make('store_url')->label('رابط صفحة التطبيق في المتجر')->url()->columnSpanFull()
                ->helperText(fn (?AppConfig $record) => $record?->platform === 'ios'
                    ? 'من App Store Connect بعد النشر، وفيه رقم التطبيق: https://apps.apple.com/sa/app/…/id123456789'
                    : 'https://play.google.com/store/apps/details?id='.config('services.google_play.package'))
                ->rule(fn (?AppConfig $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    if (blank($value)) {
                        return;
                    }
                    $ok = $record?->platform === 'ios' ? Site::isAppStoreLink((string) $value) : Site::isPlayLink((string) $value);
                    if (! $ok) {
                        $fail($record?->platform === 'ios' ? 'رابط App Store يحمل رقم التطبيق (…/id123456789).' : 'رابط Google Play لتطبيق '.config('services.google_play.package').'.');
                    }
                }),
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
