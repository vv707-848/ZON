<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The SuperAgent's window on the data: views without names, phones, texts or account links (§9). The read-only
// user ai_reader has SELECT on these views and nothing else (deploy/mysql/ai_reader.sql). Wishes are shown only
// when their owner allows aggregated market reports.
return new class extends Migration
{
    private const VIEWS = [
        'ai_v_listings' => 'SELECT l.id, l.customer_id AS advertiser_id, l.status, l.purpose, l.property_type, l.city, l.neighborhood, l.price, l.area_built, l.area_land, l.bedrooms,
                c.role AS advertiser_role, l.views_count, (l.featured_until IS NOT NULL AND l.featured_until > NOW()) AS featured,
                l.created_at, l.published_at, l.sold_at
            FROM listings l JOIN customers c ON c.id = l.customer_id WHERE l.deleted_at IS NULL',
        'ai_v_listing_history' => 'SELECT listing_id, field, old_value, new_value, changed_at FROM listing_history',
        'ai_v_requests' => 'SELECT r.id, r.status, r.purpose, r.city, r.neighborhoods, r.property_types, r.budget_min, r.budget_max, r.rooms_min, r.urgent,
                c.role AS author_role, r.created_at
            FROM requests r JOIN customers c ON c.id = r.customer_id',
        'ai_v_projects' => 'SELECT id, developer_id, status, city, neighborhood, property_type, delivery, total_units, views_count, published_at, created_at FROM projects WHERE deleted_at IS NULL',
        'ai_v_project_units' => 'SELECT id, project_id, property_type, price, available_count, created_at FROM project_units',
        'ai_v_customers' => "SELECT c.id, c.role, c.status, c.city, c.created_at, c.last_seen_at,
                EXISTS (SELECT 1 FROM buyer_preferences p WHERE p.customer_id = c.id) AS has_preference,
                EXISTS (SELECT 1 FROM listings l WHERE l.customer_id = c.id) AS has_listing,
                EXISTS (SELECT 1 FROM requests r WHERE r.customer_id = c.id) AS has_request,
                EXISTS (SELECT 1 FROM payment_transactions t WHERE t.customer_id = c.id AND t.status IN ('paid', 'unapplied')) AS has_paid
            FROM customers c WHERE c.anonymized_at IS NULL",
        'ai_v_subscriptions' => 'SELECT s.id, p.code AS package_code, p.audience, s.source, s.starts_at, s.ends_at, s.cancelled_at FROM subscriptions s JOIN packages p ON p.id = s.package_id',
        'ai_v_payments' => 'SELECT id, purpose, status, amount_halalas, vat_halalas, created_at, paid_at FROM payment_transactions',
        'ai_v_search_misses' => 'SELECT context, city, neighborhood, property_type, budget_max, created_at FROM search_misses',
        'ai_v_search_feedback' => "SELECT context, result_type, is_match, reason IS NOT NULL AS has_reason, created_at,
                JSON_UNQUOTE(JSON_EXTRACT(query_snapshot, '$.city')) AS city, JSON_UNQUOTE(JSON_EXTRACT(query_snapshot, '$.neighborhood')) AS neighborhood
            FROM search_feedback",
        'ai_v_ai_requests' => 'SELECT agent, status, latency_ms, tokens_in, tokens_out, created_at FROM ai_requests',
        'ai_v_daily_area_stats' => 'SELECT stat_date, city, neighborhood, property_type, requests_count, listings_count, median_budget, median_price, rejection_rate FROM daily_area_stats',
        'ai_v_preferences' => 'SELECT id, city, neighborhoods, property_types, purpose, budget_min, budget_max, bedrooms, is_active, created_at, updated_at FROM buyer_preferences WHERE share_in_reports = 1',
    ];

    public function up(): void
    {
        foreach (self::VIEWS as $name => $select) {
            DB::statement("CREATE OR REPLACE VIEW {$name} AS {$select}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::VIEWS) as $name) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
        }
    }
};
