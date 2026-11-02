<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Actions\Support\SupportDesk;
use App\Enums\TicketStatus;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Models\SupportTicket;
use App\Support\Permissions\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** One thread: read it (it counts as read for staff), answer as «فريق زون», close it with what was done. */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->ticket()->markSeenBy('staff');
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => Staff::can(Permission::HandleSupport);

        return [
            Action::make('reply')->label('رد')->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn () => $can() && $this->ticket()->customer_id !== null)
                ->schema([Textarea::make('body')->label('الرد (يصل للمستخدم من «فريق زون»)')->required()->maxLength(2000)->rows(5)])
                ->action(function (array $data) {
                    app(SupportDesk::class)->replyAsStaff($this->ticket(), Staff::user(), $data['body']);
                    Notification::make()->title('وصل الرد للمستخدم')->success()->send();
                }),
            Action::make('close')->label('إغلاق')->color('gray')->icon('heroicon-o-check')
                ->visible(fn () => $can() && $this->ticket()->status !== TicketStatus::Closed)
                ->schema([Textarea::make('resolution')->label('الإجراء المتخذ (يقرؤه المستخدم)')->required()->maxLength(1000)])
                ->action(function (array $data) {
                    app(SupportDesk::class)->close($this->ticket(), Staff::user(), $data['resolution']);
                    Notification::make()->title('أُغلقت التذكرة')->success()->send();
                }),
        ];
    }

    private function ticket(): SupportTicket
    {
        return Record::as($this->getRecord(), SupportTicket::class);
    }
}
