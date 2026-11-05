<?php

namespace App\Filament\Resources\ModelEvaluations\Pages;

use App\Actions\Admin\ImportLabReport;
use App\Filament\Resources\ModelEvaluations\ModelEvaluationResource;
use App\Filament\Support\Staff;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListModelEvaluations extends ListRecords
{
    protected static string $resource = ModelEvaluationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label('رفع نتيجة')->icon('heroicon-o-arrow-up-tray')
                ->modalDescription('ملف التقرير الذي يكتبه `zon-agents lab run` على عقدة الذكاء (lab-<الملف>-<الوقت>.json).')
                ->schema([
                    FileUpload::make('report')->label('ملف التقرير')->disk('local')->directory('lab-uploads')
                        ->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(ImportLabReport::MAX_BYTES / 1024)->required(),
                ])
                ->action(function (array $data) {
                    $path = (string) $data['report'];
                    try {
                        $run = app(ImportLabReport::class)((string) Storage::disk('local')->get($path), Staff::user());
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                    Notification::make()->title("رُفعت جولة {$run->profile}")->body($run->unmetLimits() === []
                        ? 'تجتاز كل الحدود.' : 'لا تجتاز '.count($run->unmetLimits()).' من الحدود.')->success()->send();
                    $this->redirect(ModelEvaluationResource::getUrl('view', ['record' => $run]));
                }),
        ];
    }
}
