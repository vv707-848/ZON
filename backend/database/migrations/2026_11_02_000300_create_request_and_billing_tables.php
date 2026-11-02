<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Demand (search requests, seekers' wishes) and money (packages, subscriptions, payments, invoices, boosts,
// the owner's path, commissions). Amounts are integer halalas; every price shown to a user is gross.
return new class extends Migration
{
    public function up(): void
    {
        // A search request («طلب»): normal = free, urgent = paid or from a plan's quota (R01).
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('published');      // published · closed · hidden
            $table->string('purpose', 4);
            $table->string('city', 80);
            $table->json('neighborhoods');                           // several (architecture's decisions table)
            $table->json('property_types');
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->decimal('area_min', 10, 2)->nullable();
            $table->decimal('area_max', 10, 2)->nullable();
            $table->unsignedTinyInteger('rooms_min')->nullable();
            $table->unsignedTinyInteger('rooms_max')->nullable();
            $table->text('description')->nullable();
            $table->boolean('urgent')->default(false);
            $table->timestamp('auto_hidden_at')->nullable();       // hidden by reports, not by a person (HandleReport may put it back)
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('index_version')->default(0);
            $table->timestamps();
            $table->index(['city', 'status', 'created_at'], 'requests_area');
            $table->index(['customer_id', 'status']);
        });
        Ddl::in('requests', 'status', ['published', 'closed', 'hidden']);
        Ddl::jsonArrayIndex('requests', 'neighborhoods');

        // The seeker's wish — mandatory before entering (428), one when free, several with a plan.
        Schema::create('buyer_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 4)->default('sale');
            $table->string('city', 80);
            $table->json('neighborhoods');
            $table->json('property_types');                          // empty = every type
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->text('notes')->nullable();                       // the free text the matching reads
            $table->boolean('is_active')->default(true);
            $table->boolean('share_in_reports')->default(true);      // opt-out of aggregated market reports (§10.7)
            $table->timestamp('matches_seen_at')->nullable();
            $table->timestamps();
            $table->index(['city', 'is_active', 'updated_at'], 'preferences_filter');
            $table->index('customer_id');
        });
        Ddl::jsonArrayIndex('buyer_preferences', 'neighborhoods');

        // Plans and their limits: read by the code, edited from the admin panel without a release.
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();                   // broker-month · broker-year · seeker · owner
            $table->string('audience', 12);                          // broker · seeker · owner · developer
            $table->string('name', 80);
            $table->string('period', 6);                             // month · year · none
            $table->unsignedInteger('price_halalas');                // gross — what the user pays
            $table->unsignedSmallInteger('featured_quota')->default(0);
            $table->unsignedSmallInteger('urgent_listing_quota')->default(0);
            $table->unsignedSmallInteger('urgent_request_quota')->default(0);
            $table->unsignedSmallInteger('listing_limit')->nullable();       // null = unlimited
            $table->unsignedSmallInteger('inventory_limit')->nullable();
            $table->unsignedSmallInteger('preferences_limit')->default(1);
            $table->unsignedSmallInteger('ai_search_daily')->default(0);
            $table->unsignedSmallInteger('ai_matching_daily')->default(0);
            $table->unsignedSmallInteger('ai_summary_daily')->default(0);
            $table->unsignedSmallInteger('ai_chat_daily')->default(10);
            $table->string('area_requests', 8)->default('none');     // none · preview · full
            $table->boolean('can_search_requests')->default(false);
            $table->boolean('can_manage_owners')->default(false);
            $table->json('features')->nullable();                    // the plan card's lines
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Ddl::in('packages', 'period', ['month', 'year', 'none']);
        Ddl::in('packages', 'area_requests', ['none', 'preview', 'full']);

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();                     // shown to the app and the provider
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 16);                           // subscription · urgent_request · featured · urgent_listing · ad_licence
            $table->nullableMorphs('target');
            $table->unsignedInteger('amount_halalas');
            $table->unsignedInteger('vat_halalas')->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->string('provider', 12);
            $table->string('provider_ref', 80)->nullable();
            $table->string('status', 10)->default('pending');        // pending · paid · failed · unapplied · refunded
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_ref']);
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        Ddl::in('payment_transactions', 'status', ['pending', 'paid', 'failed', 'unapplied', 'refunded']);
        Ddl::in('payment_transactions', 'purpose', ['subscription', 'urgent_request', 'featured', 'urgent_listing', 'ad_licence']);

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('source', 10);                            // purchase · grant · contract
            $table->foreignId('payment_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();                // null = open-ended (a developer's contract)
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('renewal_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'ends_at']);
            $table->index('ends_at');
        });
        Ddl::in('subscriptions', 'source', ['purchase', 'grant', 'contract']);

        // ZATCA phase-1 simplified tax invoices, numbered without gaps per year (invoice_counters under a row lock).
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('number', 24)->unique();
            $table->timestamp('issued_at');
            $table->unsignedInteger('subtotal_halalas');
            $table->unsignedInteger('vat_halalas');
            $table->unsignedInteger('total_halalas');
            $table->decimal('vat_rate', 5, 4);
            $table->string('buyer_name', 120);
            $table->json('lines');
            $table->text('qr_tlv')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        // «مميز» / «عاجل» on a listing, or an urgent request: from a plan's quota, paid, or granted by staff.
        Schema::create('boost_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                              // featured · urgent_listing · urgent_request
            $table->morphs('target');
            $table->string('via', 8);                                // quota · payment · grant
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('active_until')->nullable();
            $table->timestamp('ended_notified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subscription_id', 'kind']);
            $table->index(['active_until', 'ended_notified_at']);
        });
        Ddl::in('boost_usages', 'kind', ['featured', 'urgent_listing', 'urgent_request']);
        Ddl::in('boost_usages', 'via', ['quota', 'payment', 'grant']);

        // The owner's plan, per property: brokerage contract (REGA via Nafath) → licence fee → licence → listing.
        Schema::create('owner_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('deed_number', 30);
            $table->string('stage', 10)->default('contract');        // contract · fee · licence · listing · active · closed
            $table->string('contract_status', 10)->default('submitted'); // submitted · approved · rejected · expired
            $table->string('contract_ref', 60)->nullable();
            $table->timestamp('contract_approved_at')->nullable();
            $table->foreignId('fee_payment_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('ad_license_id')->nullable()->constrained('ad_licenses')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('commission_percent', 5, 2);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'stage']);
            $table->index(['contract_status', 'last_checked_at']);
        });
        Ddl::in('owner_cases', 'stage', ['contract', 'fee', 'licence', 'listing', 'active', 'closed']);
        Ddl::in('owner_cases', 'contract_status', ['submitted', 'approved', 'rejected', 'expired']);

        // A developer's marketing contract: activates his account; commission is due per unit sold.
        Schema::create('marketing_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->date('signed_at');
            $table->date('ends_at')->nullable();
            $table->decimal('commission_percent', 5, 2);
            $table->string('document_path', 255)->nullable();
            $table->string('status', 10)->default('active');         // active · ended · cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Ddl::in('marketing_contracts', 'status', ['active', 'ended', 'cancelled']);

        // Commissions due after a sale (owners 0.5 %, developers per contract, free brokers' share of السعي).
        Schema::create('commission_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->morphs('source');                                // owner_case · marketing_contract · listing
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('sale_price', 14, 2);
            $table->decimal('percent', 5, 2);
            $table->unsignedBigInteger('amount_halalas');
            $table->string('status', 10)->default('due');            // due · paid · waived
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Ddl::in('commission_dues', 'status', ['due', 'paid', 'waived']);
    }

    public function down(): void
    {
        foreach (['commission_dues', 'marketing_contracts', 'owner_cases', 'boost_usages', 'invoice_counters', 'invoices',
            'subscriptions', 'payment_transactions', 'packages', 'buyer_preferences', 'requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
