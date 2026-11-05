<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A phone's push token belongs to the session it was registered under: ending that session (from another phone, or
// the panel) takes its push registration with it, so a lost phone stops receiving the account's notifications.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_devices', function (Blueprint $table) {
            $table->foreignId('access_token_id')->nullable()->after('customer_id')
                ->constrained('personal_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_token_id');
        });
    }
};
