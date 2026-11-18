<?php

namespace App\Filament\Resources\MarketBriefs\Pages;

use App\Filament\Resources\MarketBriefs\MarketBriefResource;
use App\Filament\Support\Record;
use App\Models\MarketBrief;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** The brief as the buyer will read it, and its link to send him (the page on the website). */
class ViewMarketBrief extends ViewRecord
{
    protected static string $resource = MarketBriefResource::class;

    protected function getHeaderActions(): array
    {
        $brief = $this->brief();

        return [
            Action::make('open')->label('فتح صفحة التقرير')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url($brief->url(), shouldOpenInNewTab: true)->visible($brief->isOpen()),
            MarketBriefResource::paidAction(),
            MarketBriefResource::closeAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $brief = $this->brief();

        return $schema->components([
            Section::make('الرابط')->description($brief->isOpen()
                ? 'أرسله للمشتري: يفتح التقرير على موقع زون حتى '.$brief->expires_at->setTimezone('Asia/Riyadh')->format('Y-m-d').'، ويُطبع أو يُحفظ PDF من المتصفح.'
                : 'أُغلق الرابط: لا يفتح التقرير لأحد.')
                ->schema([Html::make(fn () => new HtmlString('<code style="direction:ltr;display:block;word-break:break-all">'.e($brief->url()).'</code>'))]),
            Section::make($brief->number.' · '.$brief->scope())->schema([
                Html::make(fn () => new HtmlString(view('site.briefs.body', ['brief' => $brief, 'inline' => true])->render())),
            ]),
        ]);
    }

    private function brief(): MarketBrief
    {
        return Record::as($this->getRecord(), MarketBrief::class);
    }
}
