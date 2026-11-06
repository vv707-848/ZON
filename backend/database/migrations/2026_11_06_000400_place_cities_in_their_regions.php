<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Each city's administrative region (App\Enums\Region, ISO 3166-2), for the market map alone: users pick a city and a
// neighbourhood only (the owner's decision), and the app's catalog does not carry it. The area stats view gains the
// region, so market reports group by it without reading a table.
return new class extends Migration
{
    private const REGIONS = ['SA-01', 'SA-02', 'SA-03', 'SA-04', 'SA-05', 'SA-06', 'SA-07', 'SA-08', 'SA-09', 'SA-10', 'SA-11', 'SA-12', 'SA-14'];

    /** Cities a database may already hold; one added later gets its region in the panel («المدن والأحياء»). */
    private const KNOWN = [
        'الرياض' => 'SA-01', 'الخرج' => 'SA-01', 'جدة' => 'SA-02', 'مكة المكرمة' => 'SA-02', 'الطائف' => 'SA-02',
        'المدينة المنورة' => 'SA-03', 'ينبع' => 'SA-03', 'الدمام' => 'SA-04', 'الخبر' => 'SA-04', 'الظهران' => 'SA-04',
        'الأحساء' => 'SA-04', 'الجبيل' => 'SA-04', 'القطيف' => 'SA-04', 'بريدة' => 'SA-05', 'عنيزة' => 'SA-05',
        'حائل' => 'SA-06', 'تبوك' => 'SA-07', 'عرعر' => 'SA-08', 'جازان' => 'SA-09', 'نجران' => 'SA-10',
        'الباحة' => 'SA-11', 'سكاكا' => 'SA-12', 'أبها' => 'SA-14', 'خميس مشيط' => 'SA-14',
    ];

    private const STATS = 'stat_date, city, neighborhood, property_type, requests_count, listings_count, median_budget, median_price, rejection_rate';

    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('region', 5)->nullable()->after('name');
        });
        Ddl::in('cities', 'region', self::REGIONS);
        foreach (self::KNOWN as $city => $region) {
            DB::table('cities')->where('name', $city)->update(['region' => $region]);
        }
        $columns = implode(', ', array_map(fn (string $c) => 's.'.trim($c), explode(',', self::STATS)));
        DB::statement("CREATE OR REPLACE VIEW ai_v_daily_area_stats AS SELECT {$columns}, c.region FROM daily_area_stats s LEFT JOIN cities c ON c.name = s.city");
    }

    public function down(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ai_v_daily_area_stats AS SELECT '.self::STATS.' FROM daily_area_stats');
        DB::statement('ALTER TABLE `cities` DROP CHECK `cities_region_check`');
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
