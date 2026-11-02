<?php

namespace App\Filament\Resources\KbDocuments\Pages;

use App\Filament\Resources\KbDocuments\KbDocumentResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Indexing\IndexSync;
use App\Models\KbDocument;
use Filament\Resources\Pages\CreateRecord;

/** A saved document goes to the search index at once (or leaves it, when inactive or overdue). */
class CreateKbDocument extends CreateRecord
{
    protected static string $resource = KbDocumentResource::class;

    protected function afterCreate(): void
    {
        $document = Record::as($this->getRecord(), KbDocument::class);
        app(IndexSync::class)->kbDocument($document);
        Audit::log('knowledge_saved', 'حفظ وثيقة معرفة', $document);
    }
}
