<?php

namespace App\Filament\Resources\Staff;

use App\Enums\StaffType;
use App\Filament\Concerns\SuperadminOnly;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/** «الموظفون» (superadmin): staff accounts, their roles, and resetting a lost authenticator. */
class StaffResource extends Resource
{
    use SuperadminOnly;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'موظف';

    protected static ?string $pluralModelLabel = 'الموظفون';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(120),
            TextInput::make('email')->label('البريد')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('password')->label('كلمة السر')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')->dehydrated(fn (?string $state) => filled($state))->rule(Password::min(12)),
            Select::make('type')->label('النوع')->options(StaffType::options())->default(StaffType::Admin->value)->required(),
            Select::make('roles')->label('الأدوار')->relationship('roles', 'name')->multiple()->preload(),
            Toggle::make('is_active')->label('نشط')->default(true)
                ->disabled(fn (?User $record) => $record?->is(Staff::user()) ?? false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('email')->label('البريد')->searchable(),
                Columns::enum('type', 'النوع'),
                TextColumn::make('roles.name')->label('الأدوار')->badge(),
                IconColumn::make('app_authentication_secret')->label('المصادقة الثنائية')->boolean()->state(fn (User $r) => filled($r->app_authentication_secret)),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('last_login_at')->label('آخر دخول')->since()->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('reset_mfa')->label('إعادة تعيين المصادقة')->color('warning')->requiresConfirmation()
                    ->modalDescription('سيُطلب منه ربط تطبيق المصادقة من جديد عند دخوله القادم.')
                    ->action(function (User $record) {
                        $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                        Audit::log('staff_mfa_reset', 'إعادة تعيين مصادقة موظف', $record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStaff::route('/'), 'create' => CreateStaff::route('/create'), 'edit' => EditStaff::route('/{record}/edit')];
    }
}
