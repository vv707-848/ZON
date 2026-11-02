<?php

namespace App\Filament\Resources\SearchTerms;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\SearchTerms\Pages\ManageSearchTerms;
use App\Models\SearchTerm;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** «المصطلحات والمرادفات»: what people write and what they mean («حوش» → «فناء»), added to every AI search. */
class SearchTermResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = SearchTerm::class;

    protected static ?string $modelLabel = 'مصطلح';

    protected static ?string $pluralModelLabel = 'المصطلحات والمرادفات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?int $navigationSort = 2;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageSearchTerms;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('term')->label('المصطلح')->required()->maxLength(60)->unique(ignoreRecord: true),
            TagsInput::make('synonyms')->label('المرادفات')->required(),
            Toggle::make('is_active')->label('فعّال')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('term')
            ->columns([
                TextColumn::make('term')->label('المصطلح')->searchable(),
                TextColumn::make('synonyms')->label('المرادفات')->badge(),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSearchTerms::route('/')];
    }
}
