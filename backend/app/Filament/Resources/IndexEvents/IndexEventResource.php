<?php

namespace App\Filament\Resources\IndexEvents;

use App\Enums\IndexAction;
use App\Enums\IndexEntity;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\IndexEvents\Pages\ListIndexEvents;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Jobs\PushIndexEvent;
use App\Models\IndexEvent;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * «مراقبة الفهرسة»: the outbox to the search index. sent empty = the backend did not send it · acked empty = the
 * index did not take it; the last error says why. «إعادة الإرسال» queues the same signal again (same trace id).
 */
class IndexEventResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = IndexEvent::class;

    protected static ?string $slug = 'index-events';

    protected static ?string $modelLabel = 'إشارة فهرسة';

    protected static ?string $pluralModelLabel = 'مراقبة الفهرسة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $failed = IndexEvent::query()->failed()->count();

        return $failed > 0 ? (string) $failed : null;
    }

    protected static function viewPermission(): Permission
    {
        return Permission::ManageIndex;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        $resend = function (IndexEvent $event): void {
            $event->forceFill(['attempts' => 0, 'last_error' => null])->save();
            PushIndexEvent::dispatch($event->id);
        };

        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime(),
                Columns::enum('entity', 'الكيان'),
                TextColumn::make('entity_id')->label('#'),
                Columns::enum('action', 'الإجراء', ['create' => 'success', 'update' => 'info', 'delete' => 'gray']),
                TextColumn::make('version')->label('النسخة'),
                TextColumn::make('sent_at')->label('أُرسل')->since()->placeholder('لم يُرسل'),
                TextColumn::make('acked_at')->label('أكّده الفهرس')->since()->placeholder('—')
                    ->description(fn (IndexEvent $e) => $e->acked_at && $e->sent_at ? $e->sent_at->diffInSeconds($e->acked_at).' ث' : null),
                TextColumn::make('attempts')->label('المحاولات'),
                TextColumn::make('last_error')->label('آخر خطأ')->limit(40)->tooltip(fn (IndexEvent $e) => $e->last_error)->placeholder('—'),
                TextColumn::make('trace_id')->label('رقم التتبع')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filters::trace(),
                Filter::make('failed')->label('فاشلة')->query(fn ($q) => $q->failed()),
                Filter::make('pending')->label('بانتظار التأكيد')->query(fn ($q) => $q->pending()),
                SelectFilter::make('entity')->label('الكيان')->options(IndexEntity::options()),
                SelectFilter::make('action')->label('الإجراء')->options(IndexAction::options()),
            ])
            ->recordActions([
                Action::make('resend')->label('إعادة الإرسال')->icon('heroicon-o-arrow-path')
                    ->visible(fn (IndexEvent $e) => $e->acked_at === null && Staff::can(Permission::ManageIndex))
                    ->action(function (IndexEvent $record) use ($resend) {
                        $resend($record);
                        Audit::log('index_resent', 'إعادة إرسال إشارة فهرسة', $record);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('resend_selected')->label('إعادة إرسال المحدد')
                    ->action(function (Collection $records) use ($resend) {
                        $records->whereNull('acked_at')->each(fn ($record) => $resend(Record::as($record, IndexEvent::class)));
                        Audit::log('index_resent', 'إعادة إرسال إشارات فهرسة', null, ['count' => $records->count()]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIndexEvents::route('/')];
    }
}
