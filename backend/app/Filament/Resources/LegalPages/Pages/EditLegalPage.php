<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Models\LegalPage;
use Filament\Resources\Pages\EditRecord;

/** Every save publishes the policy: a draft becomes version 1, a published policy a new version. */
class EditLegalPage extends EditRecord
{
    protected static string $resource = LegalPageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $page = $this->page();
        $version = $page->published_at === null ? $page->version : (string) ((int) $page->version + 1);

        return $data + ['version' => $version, 'published_at' => now(), 'updated_by' => Staff::user()->id];
    }

    protected function afterSave(): void
    {
        Audit::log('legal_published', 'نشر نسخة سياسة', $this->page(), ['version' => $this->page()->version]);
    }

    private function page(): LegalPage
    {
        return Record::as($this->getRecord(), LegalPage::class);
    }
}
