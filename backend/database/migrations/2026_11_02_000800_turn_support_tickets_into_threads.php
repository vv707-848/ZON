<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A support ticket becomes a thread: a complaint, a suggestion or a question from a user, or a message staff
 * start («رسالة من فريق زون»), with the replies of both sides, what was done when it closed (resolution), and
 * who has read up to where. The one body and the one reply of a ticket become its first two messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('type', 12)->default('question')->after('reference');      // complaint · suggestion · question · notice
            $table->string('subject', 160)->nullable()->after('phone');
            $table->string('opened_by', 8)->default('customer')->after('subject');     // customer · staff
            $table->foreignId('opened_by_staff_id')->nullable()->after('opened_by')->constrained('users')->nullOnDelete();
            $table->nullableMorphs('about');                                             // the listing it is about
            $table->text('resolution')->nullable();                                      // what was done, when closed
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedBigInteger('customer_seen_id')->nullable();                // the last message each side has read
            $table->unsignedBigInteger('staff_seen_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['customer_id', 'last_message_at']);
        });
        Ddl::in('support_tickets', 'type', ['complaint', 'suggestion', 'question', 'notice']);
        Ddl::in('support_tickets', 'opened_by', ['customer', 'staff']);

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author', 8);                                                 // customer · staff
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['support_ticket_id', 'id']);
        });
        Ddl::in('support_messages', 'author', ['customer', 'staff']);

        foreach (DB::table('support_tickets')->orderBy('id')->get() as $ticket) {
            DB::table('support_messages')->insert(['support_ticket_id' => $ticket->id, 'author' => 'customer', 'body' => $ticket->body,
                'created_at' => $ticket->created_at, 'updated_at' => $ticket->created_at]);
            if ($ticket->reply !== null) {
                $at = $ticket->replied_at ?? $ticket->updated_at;
                DB::table('support_messages')->insert(['support_ticket_id' => $ticket->id, 'author' => 'staff', 'staff_id' => $ticket->replied_by,
                    'body' => $ticket->reply, 'created_at' => $at, 'updated_at' => $at]);
            }
            DB::table('support_tickets')->where('id', $ticket->id)->update([
                'first_response_at' => $ticket->replied_at,
                'last_message_at' => $ticket->replied_at ?? $ticket->created_at,
            ]);
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replied_by');
            $table->dropColumn(['body', 'reply', 'replied_at']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->text('body')->nullable();
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
        });
        foreach (DB::table('support_tickets')->get(['id']) as $ticket) {
            $first = DB::table('support_messages')->where('support_ticket_id', $ticket->id)->where('author', 'customer')->orderBy('id')->first();
            $reply = DB::table('support_messages')->where('support_ticket_id', $ticket->id)->where('author', 'staff')->orderBy('id')->first();
            DB::table('support_tickets')->where('id', $ticket->id)->update([
                'body' => $first->body ?? '—', 'reply' => $reply?->body, 'replied_by' => $reply?->staff_id, 'replied_at' => $reply?->created_at,
            ]);
        }
        Schema::dropIfExists('support_messages');
        DB::statement('ALTER TABLE `support_tickets` DROP CHECK `support_tickets_type_check`');
        DB::statement('ALTER TABLE `support_tickets` DROP CHECK `support_tickets_opened_by_check`');
        // MySQL let the foreign key on customer_id move to the composite index (dropping its own): give it one back.
        $ownIndex = collect(Schema::getIndexes('support_tickets'))->contains(fn (array $index) => $index['columns'] === ['customer_id']);
        Schema::table('support_tickets', function (Blueprint $table) use ($ownIndex) {
            if (! $ownIndex) {
                $table->index('customer_id');
            }
            $table->dropIndex(['customer_id', 'last_message_at']);
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('opened_by_staff_id');
            $table->dropMorphs('about');
            $table->dropColumn(['type', 'subject', 'opened_by', 'resolution', 'first_response_at', 'last_message_at',
                'customer_seen_id', 'staff_seen_id', 'closed_at']);
        });
    }
};
