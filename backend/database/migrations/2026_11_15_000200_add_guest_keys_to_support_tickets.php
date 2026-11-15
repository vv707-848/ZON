<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->char('guest_operation_key', 64)->nullable()->unique();
            $table->char('guest_request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique(['guest_operation_key']);
            $table->dropColumn(['guest_operation_key', 'guest_request_hash']);
        });
    }
};
