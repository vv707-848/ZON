<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Store review and test accounts live in a sandbox (App\Support\Sandbox): every public query asks which accounts
// are testers (an index for it), and the SuperAgent's views leave out what testers made or paid. The AI's load
// (ai_v_ai_requests) still counts them; their search misses and «مطابق/غير مطابق» marks are not recorded at all.
return new class extends Migration
{
    private const TESTERS = '(SELECT id FROM customers WHERE is_tester = 1)';

    /** The views as 2026_11_02_000600 made them, for down(). */
    private const BEFORE = [
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
        'ai_v_preferences' => 'SELECT id, city, neighborhoods, property_types, purpose, budget_min, budget_max, bedrooms, is_active, created_at, updated_at FROM buyer_preferences WHERE share_in_reports = 1',
    ];

    /** @return array<string, string> */
    private function after(): array
    {
        $testers = self::TESTERS;

        return [
            'ai_v_listings' => 'SELECT l.id, l.customer_id AS advertiser_id, l.status, l.purpose, l.property_type, l.city, l.neighborhood, l.price, l.area_built, l.area_land, l.bedrooms,
                    c.role AS advertiser_role, l.views_count, (l.featured_until IS NOT NULL AND l.featured_until > NOW()) AS featured,
                    l.created_at, l.published_at, l.sold_at
                FROM listings l JOIN customers c ON c.id = l.customer_id WHERE l.deleted_at IS NULL AND c.is_tester = 0',
            'ai_v_listing_history' => "SELECT h.listing_id, h.field, h.old_value, h.new_value, h.changed_at
                FROM listing_history h JOIN listings l ON l.id = h.listing_id WHERE l.customer_id NOT IN {$testers}",
            'ai_v_requests' => 'SELECT r.id, r.status, r.purpose, r.city, r.neighborhoods, r.property_types, r.budget_min, r.budget_max, r.rooms_min, r.urgent,
                    c.role AS author_role, r.created_at
                FROM requests r JOIN customers c ON c.id = r.customer_id WHERE c.is_tester = 0',
            'ai_v_projects' => "SELECT id, developer_id, status, city, neighborhood, property_type, delivery, total_units, views_count, published_at, created_at
                FROM projects WHERE deleted_at IS NULL AND developer_id NOT IN {$testers}",
            'ai_v_project_units' => "SELECT u.id, u.project_id, u.property_type, u.price, u.available_count, u.created_at
                FROM project_units u JOIN projects p ON p.id = u.project_id WHERE p.developer_id NOT IN {$testers}",
            'ai_v_customers' => "SELECT c.id, c.role, c.status, c.city, c.created_at, c.last_seen_at,
                    EXISTS (SELECT 1 FROM buyer_preferences p WHERE p.customer_id = c.id) AS has_preference,
                    EXISTS (SELECT 1 FROM listings l WHERE l.customer_id = c.id) AS has_listing,
                    EXISTS (SELECT 1 FROM requests r WHERE r.customer_id = c.id) AS has_request,
                    EXISTS (SELECT 1 FROM payment_transactions t WHERE t.customer_id = c.id AND t.status IN ('paid', 'unapplied')) AS has_paid
                FROM customers c WHERE c.anonymized_at IS NULL AND c.is_tester = 0",
            'ai_v_subscriptions' => "SELECT s.id, p.code AS package_code, p.audience, s.source, s.starts_at, s.ends_at, s.cancelled_at
                FROM subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.customer_id NOT IN {$testers}",
            'ai_v_payments' => "SELECT id, purpose, status, amount_halalas, vat_halalas, created_at, paid_at FROM payment_transactions WHERE customer_id NOT IN {$testers}",
            'ai_v_preferences' => "SELECT id, city, neighborhoods, property_types, purpose, budget_min, budget_max, bedrooms, is_active, created_at, updated_at
                FROM buyer_preferences WHERE share_in_reports = 1 AND customer_id NOT IN {$testers}",
        ];
    }

    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->index('is_tester'));
        foreach ($this->after() as $name => $select) {
            DB::statement("CREATE OR REPLACE VIEW {$name} AS {$select}");
        }
    }

    public function down(): void
    {
        foreach (self::BEFORE as $name => $select) {
            DB::statement("CREATE OR REPLACE VIEW {$name} AS {$select}");
        }
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex(['is_tester']));
    }
};
