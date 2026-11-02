<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Concerns\SuperadminOnly;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

/** «الأدوار والصلاحيات» (superadmin): a role is a set of the panel's permissions. */
class RoleResource extends Resource
{
    use SuperadminOnly;

    protected static ?string $model = Role::class;

    protected static ?string $modelLabel = 'دور';

    protected static ?string $pluralModelLabel = 'الأدوار والصلاحيات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $options = [];
        foreach (Permission::cases() as $permission) {
            $options[$permission->value] = $permission->label();
        }

        return $schema->components([
            TextInput::make('name')->label('اسم الدور')->required()->maxLength(80)->unique(ignoreRecord: true),
            Hidden::make('guard_name')->default('web'),
            CheckboxList::make('permissions')->label('الصلاحيات')->relationship('permissions', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $options[$record->name] ?? $record->name)->columns(2)->bulkToggleable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['permissions', 'users']))
            ->columns([
                TextColumn::make('name')->label('الدور'),
                TextColumn::make('permissions_count')->label('الصلاحيات'),
                TextColumn::make('users_count')->label('الموظفون'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRoles::route('/'), 'create' => CreateRole::route('/create'), 'edit' => EditRole::route('/{record}/edit')];
    }
}
