<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Market reports by buy or rent. Since #103 every request, wish and search says which, but the daily snapshot summed
// them: a yearly rent's budget sat in the same median as a sale's, and rentals counted as supply for buyers. The
// snapshot gains `purpose` (rows written before it were all counted as sales: nothing ran in production yet).
//
// And the demand nobody posted: each semantic search (POST /ai/search) leaves its criteria in search_demand — city,
// neighbourhoods, types, buy or rent, the top of the budget, rooms — with no text, no account and no device, only
// `searcher`, a keyed hash of the account that changes every month (to count people, not clicks). Testers leave none.
return new class extends Migration
{
    private const STATS = 'stat_date, city, neighborhood, property_type, requests_count, listings_count, median_budget, median_price, rejection_rate';

    public function up(): void
    {
        Schema::table('daily_area_stats', function (Blueprint $table) {
            $table->string('purpose', 4)->default('sale')->after('property_type');
        });
        Ddl::in('daily_area_stats', 'purpose', ['sale', 'rent']);
        Schema::table('daily_area_stats', function (Blueprint $table) {
            $table->dropUnique('daily_area_stats_key');
            $table->unique(['stat_date', 'city', 'neighborhood', 'property_type', 'purpose'], 'daily_area_stats_key');
        });

        Schema::table('search_misses', function (Blueprint $table) {
            $table->string('purpose', 4)->nullable()->after('property_type');
        });

        Schema::create('search_demand', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 4);
            $table->string('city', 80);
            $table->json('neighborhoods');
            $table->json('property_types');                      // [] = any type
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->decimal('area', 10, 2)->nullable();          // as the seeker wrote it («300 متر»)
            $table->char('searcher', 16);                       // HMAC(app key, month, account): people, not clicks
            $table->timestamp('created_at');
            $table->index(['city', 'purpose', 'created_at']);
        });
        Ddl::in('search_demand', 'purpose', ['sale', 'rent']);

        $columns = implode(', ', array_map(fn (string $c) => 's.'.trim($c), explode(',', self::STATS.', purpose')));
        DB::statement("CREATE OR REPLACE VIEW ai_v_daily_area_stats AS SELECT {$columns}, c.region FROM daily_area_stats s LEFT JOIN cities c ON c.name = s.city");
        DB::statement('CREATE OR REPLACE VIEW ai_v_search_misses AS SELECT context, city, neighborhood, property_type, purpose, budget_max, created_at FROM search_misses');
        DB::statement('CREATE OR REPLACE VIEW ai_v_search_demand AS SELECT id, purpose, city, neighborhoods, property_types, budget_max, bedrooms, area, searcher, created_at FROM search_demand');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ai_v_search_demand');
        DB::statement('CREATE OR REPLACE VIEW ai_v_search_misses AS SELECT context, city, neighborhood, property_type, budget_max, created_at FROM search_misses');
        $columns = implode(', ', array_map(fn (string $c) => 's.'.trim($c), explode(',', self::STATS)));
        DB::statement("CREATE OR REPLACE VIEW ai_v_daily_area_stats AS SELECT {$columns}, c.region FROM daily_area_stats s LEFT JOIN cities c ON c.name = s.city");
        Schema::dropIfExists('search_demand');
        Schema::table('search_misses', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
        DB::table('daily_area_stats')->where('purpose', 'rent')->delete();
        DB::statement('ALTER TABLE `daily_area_stats` DROP CHECK `daily_area_stats_purpose_check`');
        Schema::table('daily_area_stats', function (Blueprint $table) {
            $table->dropUnique('daily_area_stats_key');
            $table->unique(['stat_date', 'city', 'neighborhood', 'property_type'], 'daily_area_stats_key');
            $table->dropColumn('purpose');
        });
    }
};
