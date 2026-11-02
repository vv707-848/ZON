<?php

namespace App\Filament\Resources\ChatMessages;

use App\Enums\ChatIntent;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ChatMessages\Pages\ListChatMessages;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\ChatMessage;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «مراجعة الشات»: the chat's turns (questions are stored masked). The replaced answers show what the guard
 * stopped and which knowledge is missing — the place to grow the library from.
 */
class ChatMessageResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = ChatMessage::class;

    protected static ?string $slug = 'chat-review';

    protected static ?string $modelLabel = 'محادثة شات';

    protected static ?string $pluralModelLabel = 'مراجعة الشات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?int $navigationSort = 5;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageKnowledge;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->since(),
                Columns::enum('intent', 'النية'),
                TextColumn::make('lang')->label('اللغة')->badge()->color('gray'),
                TextColumn::make('question')->label('السؤال (مُخفى)')->limit(60)->tooltip(fn (ChatMessage $m) => $m->question),
                TextColumn::make('answer')->label('الرد')->limit(60)->tooltip(fn (ChatMessage $m) => $m->answer),
                TextColumn::make('guard_status')->label('الحارس')->badge()->color(fn (string $state) => $state === 'replaced' ? 'danger' : 'success')
                    ->formatStateUsing(fn (string $state) => $state === 'replaced' ? 'استُبدل' : 'سليم'),
                TextColumn::make('ai_status')->label('الذكاء')->badge()->color('gray'),
                TextColumn::make('trace_id')->label('التتبع')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('guard_status')->label('الحارس')->options(['replaced' => 'استُبدل', 'passed' => 'سليم']),
                SelectFilter::make('intent')->label('النية')->options(ChatIntent::options()),
                Filters::trace(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListChatMessages::route('/')];
    }
}
