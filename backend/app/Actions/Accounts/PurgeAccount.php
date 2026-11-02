<?php

namespace App\Actions\Accounts;

use App\Models\ChatMessage;
use App\Models\DeletionRequest;
use App\Models\Message;
use App\Models\NoteSummary;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The end of the grace period: the account keeps its id (sales and payments stay accountable) but loses its
 * identity, its private desk (clients, owners, notes, tasks, stock, wishes) and its devices.
 */
final class PurgeAccount
{
    public function __invoke(DeletionRequest $deletion): void
    {
        if (! $deletion->isPending() || $deletion->purge_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($deletion) {
            $customer = $deletion->customer;
            $customer->tokens()->delete();
            $customer->devices()->delete();
            $customer->clients()->delete();          // notes go with them (cascade)
            $customer->propertyOwners()->delete();
            $customer->tasks()->delete();
            $customer->inventoryItems()->delete();
            $customer->preferences()->delete();
            NoteSummary::query()->where('customer_id', $customer->id)->delete();           // summaries of his clients' notes
            ChatMessage::query()->where('customer_id', $customer->id)->delete();           // his questions to the chat
            $customer->listings()->withTrashed()->update(['phones' => null]);
            $customer->projects()->withTrashed()->update(['phones' => null]);
            Message::query()->where('sender_id', $customer->id)->update(['body' => '—']);
            // Support threads keep their record (number, dates, what was done), not his words or his number.
            SupportMessage::query()->where('author', 'customer')
                ->whereIn('support_ticket_id', SupportTicket::query()->where('customer_id', $customer->id)->select('id'))->update(['body' => '—']);
            SupportTicket::query()->where('customer_id', $customer->id)->update(['name' => 'حساب محذوف', 'phone' => null]);
            DB::table('favorites')->where('customer_id', $customer->id)->delete();
            DB::table('follows')->where('follower_id', $customer->id)->orWhere('followed_id', $customer->id)->delete();
            DB::table('user_blocks')->where('blocker_id', $customer->id)->orWhere('blocked_id', $customer->id)->delete();

            $customer->forceFill([
                'name' => 'حساب محذوف',
                'username' => "deleted-{$customer->id}",
                'phone' => "deleted:{$customer->id}",
                'password' => Str::random(40),
                'fal_number' => null,
                'notification_prefs' => null,
                'signup_device_hash' => null,
                'signup_ip_hash' => null,
                'anonymized_at' => now(),
            ])->save();
            $deletion->forceFill(['purged_at' => now()])->save();
            activity('accounts')->performedOn($customer)->event('purged')->log('حذف الحساب نهائياً');
        });
    }
}
