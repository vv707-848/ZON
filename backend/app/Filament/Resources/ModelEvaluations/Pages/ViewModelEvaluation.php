<?php

namespace App\Filament\Resources\ModelEvaluations\Pages;

use App\Filament\Resources\ModelEvaluations\ModelEvaluationResource;
use App\Filament\Support\Audit;
use App\Models\ModelEvaluation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewModelEvaluation extends ViewRecord
{
    protected static string $resource = ModelEvaluationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('حذف الجولة')
                ->before(fn (ModelEvaluation $record) => Audit::log('lab_report_deleted', 'حذف نتيجة من مختبر النماذج', $record, ['profile' => $record->profile])),
        ];
    }
}
