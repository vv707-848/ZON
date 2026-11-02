<?php

namespace App\Filament\Resources\MarketingContracts;

use App\Enums\MarketingContractStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MarketingContracts\Pages\EditMarketingContract;
use App\Filament\Resources\MarketingContracts\Pages\ListMarketingContracts;
use App\Filament\Support\Columns;
use App\Models\MarketingContract;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Developers' marketing contracts: the commission per unit sold, the end date, the status. */
class MarketingContractResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = MarketingContract::class;

    protected static ?string $modelLabel = 'عقد تسويق';

    protected static ?string $pluralModelLabel = 'عقود التسويق';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    protected static function viewPermission(): Permission
    {
        return Permission::ActivateDevelopers;
    }

    public static function canCreate(): bool
    {
        return false;                                  // created with «تسجيل عقد تسويق» on the developer
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('commission_percent')->label('العمولة (٪)')->numeric()->minValue(0)->maxValue(100)->required(),
            DatePicker::make('ends_at')->label('ينتهي'),
            Select::make('status')->label('الحالة')->options(MarketingContractStatus::options())->required(),
            Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['developer', 'project']))
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('developer.name')->label('المطوّر')->searchable(),
                TextColumn::make('project.title')->label('المشروع')->placeholder('كل المشاريع'),
                TextColumn::make('commission_percent')->label('العمولة ٪'),
                TextColumn::make('signed_at')->label('التوقيع')->date(),
                TextColumn::make('ends_at')->label('الانتهاء')->date()->placeholder('—'),
                Columns::enum('status', 'الحالة', ['active' => 'success', 'ended' => 'gray', 'cancelled' => 'danger']),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(MarketingContractStatus::options())])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketingContracts::route('/'), 'edit' => EditMarketingContract::route('/{record}/edit')];
    }
}
