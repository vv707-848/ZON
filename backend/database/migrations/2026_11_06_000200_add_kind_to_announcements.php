<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An announcement is a service message or a promotion (App\Enums\AnnouncementKind): a promotion reaches every bell
// but only the phones whose owners agreed to offers. `pushed` counts the phones' owners a push was sent to.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('kind', 12)->default('service')->after('id');
            $table->unsignedInteger('pushed')->default(0)->after('recipients');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'pushed']);
        });
    }
};
