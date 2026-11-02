<?php

namespace App\Actions\Support;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Support\Audit;
use App\Models\Customer;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Notices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way a support thread changes: opened by a user or a visitor, started by staff, answered by either
 * side, closed with what was done. The user hears of every staff message; staff see the thread in the panel.
 */
final class SupportDesk
{
    /** A user (or a visitor, without an account) writes first. */
    public function open(?Customer $customer, TicketType $type, string $body, ?string $subject = null, ?Model $about = null,
        ?string $name = null, ?string $phone = null): SupportTicket
    {
        return DB::transaction(function () use ($customer, $type, $body, $subject, $about, $name, $phone) {
            $ticket = SupportTicket::query()->create([
                'reference' => SupportTicket::newReference(),
                'type' => $type,
                'customer_id' => $customer?->id,
                'name' => $customer->name ?? (string) $name,
                'phone' => $customer->phone ?? $phone,
                'subject' => $subject,
                'opened_by' => 'customer',
                'about_type' => $about?->getMorphClass(),
                'about_id' => $about?->getKey(),
                'status' => TicketStatus::Open,
            ]);
            $this->write($ticket, 'customer', null, $body);

            return $ticket;
        });
    }

    /** Staff write first («رسالة من فريق زون»): the user is told, and answers in the same thread. */
    public function message(Customer $customer, User $staff, string $subject, string $body, ?Model $about = null): SupportTicket
    {
        $ticket = DB::transaction(function () use ($customer, $staff, $subject, $body, $about) {
            $ticket = SupportTicket::query()->create([
                'reference' => SupportTicket::newReference(),
                'type' => TicketType::Notice,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'subject' => $subject,
                'opened_by' => 'staff',
                'opened_by_staff_id' => $staff->id,
                'about_type' => $about?->getMorphClass(),
                'about_id' => $about?->getKey(),
                'status' => TicketStatus::Answered,
            ]);
            $message = $this->write($ticket, 'staff', $staff, $body);
            $customer->notify(Notices::supportMessage($ticket, $message));

            return $ticket;
        });
        Audit::log('customer_messaged', 'مراسلة مستخدم', $ticket, ['subject' => $subject], $staff);

        return $ticket;
    }

    /** The user answers; a closed thread opens again. */
    public function replyAsCustomer(SupportTicket $ticket, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $body) {
            $ticket->forceFill(['status' => TicketStatus::Open, 'closed_at' => null, 'closed_by' => null])->save();

            return $this->write($ticket, 'customer', null, $body);
        });
    }

    public function replyAsStaff(SupportTicket $ticket, User $staff, string $body): SupportMessage
    {
        $message = DB::transaction(function () use ($ticket, $staff, $body) {
            $ticket->forceFill([
                'status' => TicketStatus::Answered,
                'first_response_at' => $ticket->first_response_at ?? now(),
            ])->save();
            $message = $this->write($ticket, 'staff', $staff, $body);
            $ticket->customer?->notify(Notices::supportMessage($ticket, $message));

            return $message;
        });
        Audit::log('ticket_replied', 'رد على تذكرة', $ticket, by: $staff);

        return $message;
    }

    /** Closed with what was done — the user reads it in the thread. */
    public function close(SupportTicket $ticket, User $staff, string $resolution): void
    {
        $ticket->forceFill([
            'status' => TicketStatus::Closed,
            'resolution' => $resolution,
            'closed_at' => now(),
            'closed_by' => $staff->id,
            'first_response_at' => $ticket->first_response_at ?? now(),
        ])->save();
        $ticket->customer?->notify(Notices::supportClosed($ticket));
        Audit::log('ticket_closed', 'إغلاق تذكرة', $ticket, ['resolution' => $resolution], $staff);
    }

    private function write(SupportTicket $ticket, string $author, ?User $staff, string $body): SupportMessage
    {
        $message = $ticket->messages()->create(['author' => $author, 'staff_id' => $staff?->id, 'body' => $body]);
        // The writer has read his own message, and everything before it.
        $ticket->forceFill(['last_message_at' => $message->created_at, $author === 'staff' ? 'staff_seen_id' : 'customer_seen_id' => $message->id])->save();

        return $message;
    }
}
