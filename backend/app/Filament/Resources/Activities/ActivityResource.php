<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Support\Filters;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * «سجل التدقيق»: who did what to which record, and why — staff actions (log «admin»), account events (sign-up,
 * sign-in, deletion), payments. Each entry carries the trace id of the action that wrote it.
 */
class ActivityResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'audit';

    protected static ?string $modelLabel = 'قيد تدقيق';

    protected static ?string $pluralModelLabel = 'سجل التدقيق';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 4;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewAudit;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime(),
                TextColumn::make('log_name')->label('السجل')->badge()->color('gray'),
                TextColumn::make('description')->label('الوصف')->searchable(),
                TextColumn::make('event')->label('الحدث')->toggleable(),
                TextColumn::make('causer')->label('بواسطة')->state(fn (Activity $a) => $a->causer === null ? 'النظام'
                    : ($a->causer_type === 'user' ? 'موظف: ' : 'حساب: ').($a->causer->getAttribute('name') ?? "#{$a->causer_id}")),
                TextColumn::make('subject')->label('على')->state(fn (Activity $a) => $a->subject_type ? "{$a->subject_type} #{$a->subject_id}" : '—'),
                TextColumn::make('properties.trace_id')->label('رقم التتبع')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filters::trace('properties->trace_id'),
                SelectFilter::make('log_name')->label('السجل')->options(fn () => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('causer_type')->label('بواسطة')->options(['user' => 'موظف', 'customer' => 'حساب']),
                Filters::dateRange('created_at', 'الوقت'),
            ])
            ->recordActions([
                Action::make('details')->label('التفاصيل')->icon('heroicon-o-eye')->modalSubmitAction(false)
                    ->modalContent(fn (Activity $record) => new HtmlString('<pre dir="ltr" class="text-xs whitespace-pre-wrap">'
                        .e((string) json_encode($record->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
