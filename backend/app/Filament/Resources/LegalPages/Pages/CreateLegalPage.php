<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Models\LegalPage;
use Filament\Resources\Pages\CreateRecord;

/** «سياسة جديدة»: saved means published, as version 1 (as every save of a policy publishes it). */
class CreateLegalPage extends CreateRecord
{
    protected static string $resource = LegalPageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['version' => '1', 'published_at' => now(), 'updated_by' => Staff::user()->id];
    }

    protected function afterCreate(): void
    {
        $page = Record::as($this->getRecord(), LegalPage::class);
        Audit::log('legal_published', 'نشر صفحة سياسة جديدة', $page, ['version' => $page->version]);
    }
}
