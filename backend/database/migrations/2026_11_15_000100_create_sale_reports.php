<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «تم البيع» as the market sees it: every sale an advertiser marks (a listing, or units of a project's model), with
// the price it was listed at and — when he gave it — the price it sold for. Kept apart from commissions (which
// exist only under a contract and fall back to the listed price): what was said is what is reported. The
// SuperAgent reads it through ai_v_sales (testers left out), by area and type only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 16);                      // listing · project_unit
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('count')->default(1);
            $table->string('purpose', 4);                            // sale · rent
            $table->string('property_type', 12);
            $table->string('city', 64);
            $table->string('neighborhood', 64)->nullable();
            $table->decimal('listed_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();        // only what he said: no price is no price
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['city', 'neighborhood', 'property_type', 'created_at'], 'sale_reports_area');
        });
        Ddl::in('sale_reports', 'subject_type', ['listing', 'project_unit']);
        Ddl::in('sale_reports', 'purpose', ['sale', 'rent']);

        DB::statement('CREATE OR REPLACE VIEW ai_v_sales AS SELECT s.id, s.subject_type, s.count, s.purpose, s.property_type, s.city, s.neighborhood,
                s.listed_price, s.sale_price, s.created_at
            FROM sale_reports s WHERE s.customer_id IS NULL OR s.customer_id NOT IN (SELECT id FROM customers WHERE is_tester = 1)');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ai_v_sales');
        Schema::dropIfExists('sale_reports');
    }
};
