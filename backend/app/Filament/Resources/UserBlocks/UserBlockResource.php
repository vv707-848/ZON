<?php

namespace App\Filament\Resources\UserBlocks;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\UserBlocks\Pages\ListUserBlocks;
use App\Models\UserBlock;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** «المحظورون»: who blocked whom — read only (it is the user's decision); many blocks of one account is a signal. */
class UserBlockResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = UserBlock::class;

    protected static ?string $slug = 'blocks';

    protected static ?string $modelLabel = 'حظر';

    protected static ?string $pluralModelLabel = 'المحظورون';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Moderation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?int $navigationSort = 2;

    protected static function viewPermission(): Permission
    {
        return Permission::HandleReports;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['blocker', 'blocked']))
            ->columns([
                TextColumn::make('blocked.name')->label('المحظور')->searchable(),
                TextColumn::make('blocked_total')->label('حظره')->state(fn (UserBlock $r) => UserBlock::query()->where('blocked_id', $r->blocked_id)->count().' حساب'),
                TextColumn::make('blocker.name')->label('الحاظر'),
                TextColumn::make('created_at')->label('التاريخ')->since(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUserBlocks::route('/')];
    }
}
