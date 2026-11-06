<?php

namespace App\Filament\Widgets;

use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Filament\Support\Staff;
use App\Models\SearchFeedback;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Why users said «غير مطابق» (masked reasons), and a one-click summary of the themes (summarize_batch). */
class MismatchReasons extends TableWidget
{
    protected static ?string $heading = 'أسباب «غير مطابق»';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SearchFeedback::query()->where('is_match', false)->whereNotNull('reason'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->since(),
                TextColumn::make('context')->label('الخدمة')->badge()->color('gray'),
                TextColumn::make('reason')->label('السبب')->wrap(),
            ])
            ->headerActions([
                Action::make('themes')->label('تلخيص الأسباب بالذكاء')->icon('heroicon-o-sparkles')
                    ->action(function () {
                        $texts = SearchFeedback::query()->where('is_match', false)->whereNotNull('reason')->latest('id')->limit(300)->pluck('reason')->all();
                        $result = app(AiGateway::class)->call(AiAgent::SummarizeBatch, ['mode' => 'map', 'purpose' => 'أسباب «غير مطابق» في نتائج المطابقة والبحث', 'texts' => $texts],
                            check: fn (array $out) => trim((string) ($out['summary'] ?? '')) === '' ? null : $out, staff: Staff::user());
                        $body = $result->usable()
                            ? $result->output['summary']."\n".collect((array) ($result->output['themes'] ?? []))->map(fn ($t) => '• '.($t['theme'] ?? '').' ('.($t['share'] ?? '').')')->implode("\n")
                            : 'تعذّر التلخيص الآن.';
                        Notification::make()->title('أبرز الأسباب')->body($body)->persistent()->send();
                    })
                    ->visible(fn () => SearchFeedback::query()->where('is_match', false)->whereNotNull('reason')->exists()),
            ]);
    }
}
