<?php

namespace App\Filament\Resources\SupportTickets;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Support\Columns;
use App\Models\Listing;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * «الشكاوى والمراسلات»: complaints, suggestions and questions from users and visitors, and the messages staff
 * start («رسالة من فريق زون»). «مفتوحة» means it is staff's turn; the badge counts them.
 */
class SupportTicketResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = SupportTicket::class;

    protected static ?string $slug = 'support';

    protected static ?string $modelLabel = 'تذكرة';

    protected static ?string $pluralModelLabel = 'الشكاوى والمراسلات';

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
        return $table->defaultSort('last_message_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['lastMessage', 'customer']))
            ->columns([
                TextColumn::make('reference')->label('رقم القيد')->searchable()->copyable(),
                Columns::enum('type', 'النوع'),
                TextColumn::make('subject')->label('الموضوع')->limit(40)
                    ->state(fn (SupportTicket $t) => $t->subject ?? Str::limit((string) $t->lastMessage?->body, 60)),
                TextColumn::make('name')->label('المستخدم')->searchable()
                    ->url(fn (SupportTicket $t) => $t->customer_id ? CustomerResource::getUrl('view', ['record' => $t->customer_id]) : null),
                Columns::phone(),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('last_message_at')->label('آخر رسالة')->since()->sortable(),
                TextColumn::make('created_at')->label('الفتح')->dateTime('Y-m-d H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(TicketStatus::options())->default(TicketStatus::Open->value),
                SelectFilter::make('type')->label('النوع')->options(TicketType::options()),
                SelectFilter::make('opened_by')->label('بدأها')->options(['customer' => 'المستخدم', 'staff' => 'فريق زون']),
            ])
            ->recordUrl(fn (SupportTicket $t) => static::getUrl('view', ['record' => $t]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('التذكرة')->columns(4)->schema([
                TextEntry::make('reference')->label('رقم القيد')->copyable(),
                Columns::enumEntry('type', 'النوع'),
                Columns::enumEntry('status', 'الحالة'),
                TextEntry::make('created_at')->label('الفتح')->dateTime('Y-m-d H:i'),
                TextEntry::make('name')->label('المستخدم')
                    ->url(fn (SupportTicket $t) => $t->customer_id ? CustomerResource::getUrl('view', ['record' => $t->customer_id]) : null),
                TextEntry::make('subject')->label('الموضوع')->placeholder('—'),
                TextEntry::make('about')->label('بخصوص')->placeholder('—')
                    ->state(fn (SupportTicket $t) => $t->about instanceof Listing ? "عرض: {$t->about->title}" : null)
                    ->url(fn (SupportTicket $t) => $t->about instanceof Listing ? ListingResource::getUrl('view', ['record' => $t->about_id]) : null),
                TextEntry::make('first_response_at')->label('أول رد')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('resolution')->label('الإجراء المتخذ')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('المراسلة')->schema([
                TextEntry::make('thread')->hiddenLabel()->state(fn (SupportTicket $t) => self::thread($t)),
            ]),
        ]);
    }

    /** The thread as staff read it: who wrote each message (a staff name stays inside the panel). */
    private static function thread(SupportTicket $ticket): HtmlString
    {
        $html = '';
        foreach ($ticket->messages()->with('staff')->get() as $message) {
            /** @var SupportMessage $message */
            $staff = $message->author === 'staff';
            $who = $staff ? 'فريق زون'.($message->staff ? ' ('.e($message->staff->name).')' : '') : e($ticket->name);
            $style = 'margin:0 0 10px;padding:10px 12px;border-radius:10px;max-width:85%;'
                .($staff ? 'background:#e8f3ef;margin-inline-start:auto' : 'background:#f3f4f6');
            $html .= '<div style="'.$style.'"><div style="font-size:12px;color:#6b7280">'.$who.' · '.e((string) $message->created_at?->format('Y-m-d H:i'))
                .'</div><div style="white-space:pre-line;line-height:1.8">'.e($message->body).'</div></div>';
        }

        return new HtmlString('<div style="display:flex;flex-direction:column">'.$html.'</div>');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportTickets::route('/'), 'view' => ViewSupportTicket::route('/{record}')];
    }
}
