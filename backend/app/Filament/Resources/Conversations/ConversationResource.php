<?php

namespace App\Filament\Resources\Conversations;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * «المحادثات»: who talks with whom about what, and how much — never the text. The text opens only with the
 * sensitive permission, for a conversation that has a reported message, and each look is audited.
 */
class ConversationResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Conversation::class;

    protected static ?string $modelLabel = 'محادثة';

    protected static ?string $pluralModelLabel = 'المحادثات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 5;

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
        return $table->defaultSort('last_message_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['participants.customer:id,name,role', 'listing:id,title', 'project:id,title'])->withCount('messages'))
            ->columns([
                TextColumn::make('between')->label('الطرفان')->state(fn (Conversation $c) => $c->participants->map(fn ($p) => $p->customer->name ?? '—')->implode(' ↔ ')),
                TextColumn::make('subject')->label('الموضوع')->state(fn (Conversation $c) => $c->listing->title ?? $c->project->title ?? ($c->request_id ? "طلب #{$c->request_id}" : '—'))->limit(30),
                TextColumn::make('messages_count')->label('الرسائل'),
                TextColumn::make('reported')->label('بلاغات')->state(fn (Conversation $c) => self::reportedMessages($c)->count())->badge()->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_message_at')->label('آخر رسالة')->since(),
            ])
            ->filters([TernaryFilter::make('reported')->label('فيها رسالة مُبلَّغ عنها')->queries(
                true: fn ($q) => $q->whereHas('messages', fn ($m) => $m->whereIn('id', ContentReport::query()->where('target_type', 'message')->select('target_id'))),
                false: fn ($q) => $q,
            )])
            ->recordActions([
                Action::make('content')->label('عرض النص (حساس)')->icon('heroicon-o-lock-open')->color('warning')
                    ->visible(fn (Conversation $c) => Staff::can(Permission::ViewConversationContent) && self::reportedMessages($c)->exists())
                    ->modalSubmitAction(false)
                    ->mountUsing(fn (Conversation $record) => Audit::log('conversation_viewed', 'اطلاع على نص محادثة', $record))
                    ->modalContent(fn (Conversation $record) => new HtmlString($record->messages()->with('sender:id,name')->oldest('id')->limit(200)->get()
                        ->map(fn ($m) => '<p><strong>'.e($m->sender->name ?? '—').'</strong> · '.e($m->created_at?->format('Y-m-d H:i')).'<br>'.e($m->body).'</p>')->implode(''))),
            ]);
    }

    /** @return Builder<Message> */
    private static function reportedMessages(Conversation $conversation): Builder
    {
        return $conversation->messages()->getQuery()->whereIn('id', ContentReport::query()->where('target_type', 'message')->select('target_id'));
    }

    public static function getPages(): array
    {
        return ['index' => ListConversations::route('/')];
    }
}
