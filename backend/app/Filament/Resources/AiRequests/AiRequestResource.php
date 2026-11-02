<?php

namespace App\Filament\Resources\AiRequests;

use App\Enums\AiAgent;
use App\Enums\AiCallStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AiRequests\Pages\ListAiRequests;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Filters;
use App\Models\AiRequest;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every AI call, read-only (ai_requests: no personal text, only a hash of the masked input).
 * The call behind a user's complaint is found by its trace id — the same id is in the agents
 * service log line of that call. Totals per day are on «مراقبة الذكاء».
 */
class AiRequestResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = AiRequest::class;

    protected static ?string $slug = 'ai-requests';

    protected static ?string $modelLabel = 'نداء ذكاء';

    protected static ?string $pluralModelLabel = 'سجل نداءات الذكاء';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 3;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewAi;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('staff:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime(),
                TextColumn::make('agent')->label('الوكيل')->formatStateUsing(fn (AiAgent $state) => $state->label()),
                TextColumn::make('status')->label('النتيجة')->badge()
                    ->formatStateUsing(fn (AiCallStatus $state) => $state->label())
                    ->color(fn (AiCallStatus $state) => match ($state) {
                        AiCallStatus::Ok, AiCallStatus::Cached => 'success',
                        AiCallStatus::Degraded => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('latency_ms')->label('الزمن (ms)')->numeric()->sortable(),
                TextColumn::make('tokens_in')->label('توكنات الإدخال')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tokens_out')->label('توكنات الإخراج')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requester')->label('الطالب')
                    ->state(fn (AiRequest $record) => match (true) {
                        $record->customer_id !== null => "حساب #{$record->customer_id}",
                        $record->staff_id !== null => 'مشرف: '.($record->staff->name ?? "#{$record->staff_id}"),
                        default => 'النظام',
                    })
                    ->url(fn (AiRequest $record) => $record->customer_id !== null && CustomerResource::canViewAny()
                        ? CustomerResource::getUrl('view', ['record' => $record->customer_id])
                        : null),
                TextColumn::make('trace_id')->label('رقم التتبع')->copyable()->placeholder('—'),
            ])
            ->filters([
                Filters::trace(),
                SelectFilter::make('agent')->label('الوكيل')->options(AiAgent::options()),
                SelectFilter::make('status')->label('النتيجة')->options(AiCallStatus::options()),
                Filters::dateRange('created_at', 'الوقت'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiRequests::route('/')];
    }
}
