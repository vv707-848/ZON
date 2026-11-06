<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** A SuperAgent proposal (App\SuperAgent\Proposal::Announce) opens the form on its audience, city and kind; the words are the person's. */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');
        $this->form->fill();
        $proposed = array_filter(request()->only(['audience', 'city', 'kind']), 'is_string');
        if ($proposed !== []) {
            $this->form->fill([...$this->form->getRawState(), ...$proposed]);
        }
        $this->callHook('afterFill');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => Staff::user()->id];
    }

    protected function afterCreate(): void
    {
        $announcement = Record::as($this->getRecord(), Announcement::class);
        SendAnnouncement::dispatch($announcement->id);
        Audit::log('announcement_sent', 'إرسال إشعار عام', $announcement);
    }
}
