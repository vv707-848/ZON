<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The advertising licence's life: a listing whose licence ended is «منتهي الترخيص» (licence_expired) until a
 * renewal, and its advertiser hears of the end a week and a day before (reminded_days: the last warning sent).
 */
return new class extends Migration
{
    private const STATUSES = ['draft', 'indexing', 'pending_review', 'published', 'paused', 'inactive', 'sold', 'hidden', 'rejected'];

    public function up(): void
    {
        DB::statement('ALTER TABLE `listings` DROP CHECK `listings_status_check`');
        Ddl::in('listings', 'status', [...self::STATUSES, 'licence_expired']);

        Schema::table('ad_licenses', function (Blueprint $table) {
            $table->unsignedTinyInteger('reminded_days')->nullable()->after('expires_at');   // 7 · 1
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ad_licenses', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn('reminded_days');
        });

        DB::statement('ALTER TABLE `listings` DROP CHECK `listings_status_check`');
        Ddl::in('listings', 'status', self::STATUSES);
    }
};
