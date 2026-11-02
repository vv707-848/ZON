<?php

namespace App\Filament\Resources\Ratings;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Ratings\Pages\ListRatings;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\Rating;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «التقييمات»: stars on advertisers' profiles; staff hide or restore one. */
class RatingResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Rating::class;

    protected static ?string $modelLabel = 'تقييم';

    protected static ?string $pluralModelLabel = 'التقييمات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 6;

    protected static function viewPermission(): Permission
    {
        return Permission::ModerateRatings;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['rater', 'rated']))
            ->columns([
                TextColumn::make('rated.name')->label('المُقيَّم')->searchable(),
                TextColumn::make('rater.name')->label('المقيِّم'),
                TextColumn::make('stars')->label('النجوم')->formatStateUsing(fn (int $state) => str_repeat('★', $state)),
                TextColumn::make('comment')->label('التعليق')->limit(50)->placeholder('—'),
                TextColumn::make('hidden_at')->label('مخفي منذ')->dateTime()->placeholder('ظاهر'),
                TextColumn::make('updated_at')->label('التاريخ')->date(),
            ])
            ->filters([TernaryFilter::make('hidden_at')->label('مخفي')->nullable()])
            ->recordActions([
                Action::make('toggle')->label(fn (Rating $r) => $r->hidden_at ? 'إظهار' : 'إخفاء')->color(fn (Rating $r) => $r->hidden_at ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(function (Rating $record) {
                        $record->forceFill(['hidden_at' => $record->hidden_at ? null : now()])->save();
                        Audit::log($record->hidden_at ? 'rating_hidden' : 'rating_shown', 'إشراف تقييم', $record);
                    })
                    ->visible(fn () => Staff::can(Permission::ModerateRatings)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRatings::route('/')];
    }
}
