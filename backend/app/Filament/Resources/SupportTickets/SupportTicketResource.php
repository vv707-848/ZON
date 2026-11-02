<?php

namespace App\Filament\Resources\SupportTickets;

use App\Enums\TicketStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\SupportTicket;
use App\Notifications\Notices;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «تذاكر الدعم»: the reference number the user got, his message, the reply he receives in the app. */
class SupportTicketResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = SupportTicket::class;

    protected static ?string $slug = 'support';

    protected static ?string $modelLabel = 'تذكرة';

    protected static ?string $pluralModelLabel = 'تذاكر الدعم';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 8;

    public static function getNavigationBadge(): ?string
    {
        $open = SupportTicket::query()->where('status', TicketStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    protected static function viewPermission(): Permission
    {
        return Permission::HandleSupport;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')->label('رقم القيد')->searchable()->copyable(),
                TextColumn::make('name')->label('الاسم')->searchable(),
                Columns::phone(),
                TextColumn::make('body')->label('الرسالة')->limit(60)->tooltip(fn (SupportTicket $t) => $t->body),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('created_at')->label('التاريخ')->since(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(TicketStatus::options())->default(TicketStatus::Open->value)])
            ->recordActions([
                Action::make('reply')->label('رد')->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (SupportTicket $t) => $t->status !== TicketStatus::Closed && Staff::can(Permission::HandleSupport))
                    ->fillForm(fn (SupportTicket $t) => ['reply' => $t->reply])
                    ->schema([Textarea::make('reply')->label('الرد (يصل للمستخدم في التطبيق)')->required()->maxLength(2000)])
                    ->action(function (SupportTicket $record, array $data) {
                        $record->forceFill(['reply' => $data['reply'], 'status' => TicketStatus::Answered, 'replied_by' => Staff::user()->id, 'replied_at' => now()])->save();
                        $record->customer?->notify(Notices::supportReplied($record->reference));
                        Audit::log('ticket_replied', 'رد على تذكرة دعم', $record);
                    }),
                Action::make('close')->label('إغلاق')->color('gray')->requiresConfirmation()
                    ->visible(fn (SupportTicket $t) => $t->status !== TicketStatus::Closed && Staff::can(Permission::HandleSupport))
                    ->action(fn (SupportTicket $record) => $record->forceFill(['status' => TicketStatus::Closed])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportTickets::route('/')];
    }
}
