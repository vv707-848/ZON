<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each side has read is the last message he read, not a time: a reply in the same second as his own message
 * was counted read (timestamps are whole seconds). Kept for every conversation: the last message at or before the
 * time each side last read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('last_read_at');
        });
        DB::table('conversation_participants')->whereNotNull('last_read_at')->update([
            'last_read_message_id' => DB::raw('(SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = conversation_participants.conversation_id AND m.created_at <= conversation_participants.last_read_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $table) => $table->dropColumn('last_read_message_id'));
    }
};
