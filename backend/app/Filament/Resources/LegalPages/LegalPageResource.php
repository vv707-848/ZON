<?php

namespace App\Filament\Resources\LegalPages;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\LegalPages\Pages\EditLegalPage;
use App\Filament\Resources\LegalPages\Pages\ListLegalPages;
use App\Models\LegalPage;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** «صفحات السياسات» of the app's settings: the text and its version (each save is a new version). */
class LegalPageResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = LegalPage::class;

    protected static ?string $slug = 'legal';

    protected static ?string $modelLabel = 'سياسة';

    protected static ?string $pluralModelLabel = 'صفحات السياسات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 4;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageReference;
    }

    public static function canCreate(): bool
    {
        return false;                       // the app opens five fixed slugs
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('العنوان')->required()->maxLength(160),
            Textarea::make('body')->label('النص')->required()->rows(16),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('slug')->label('المعرّف')->badge()->color('gray'),
            TextColumn::make('title')->label('العنوان'),
            TextColumn::make('version')->label('النسخة'),
            TextColumn::make('published_at')->label('النشر')->dateTime(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLegalPages::route('/'), 'edit' => EditLegalPage::route('/{record}/edit')];
    }
}
