<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reference data edited from the admin panel, and the app's accounts (customers) with what hangs on them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['city_id', 'name']);
        });

        // Business values of App\Support\Settings\Setting (defaults live in the enum; a row = an override).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // Forced update and maintenance mode, per platform (GET /api/v1/app/config).
        Schema::create('app_configs', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 8)->unique();                 // ios · android
            $table->string('min_version', 20);
            $table->string('latest_version', 20);
            $table->string('store_url', 500);
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->timestamp('maintenance_until')->nullable();
            $table->timestamps();
        });
        Ddl::in('app_configs', 'platform', ['ios', 'android']);

        // The policies shown in the app's settings (payments · privacy · ip · terms · rega).
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 20)->unique();
            $table->string('title', 160);
            $table->longText('body');
            $table->string('version', 20);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Local-terms dictionary of the retrieval layer (architecture §9): «حوش» → «فناء», «حديقة داخلية».
        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 60)->unique();
            $table->json('synonyms');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The app's users. Phone = E.164 (+9665XXXXXXXX), one phone per account.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('role', 12);                              // seeker · broker · owner · developer
            $table->string('status', 12)->default('active');         // active · pending · suspended
            $table->string('name', 120);                             // a person or a company
            $table->string('username', 32)->unique();
            $table->string('phone', 16)->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password');
            $table->string('city', 80);
            $table->string('fal_number', 30)->nullable();            // brokers' FAL licence (REGA)
            $table->string('fal_status', 10)->default('none');       // none · pending · valid · rejected · expired
            $table->timestamp('fal_checked_at')->nullable();
            $table->date('fal_expires_at')->nullable();
            $table->string('nafath_status', 10)->default('none');    // none · pending · verified · rejected
            $table->timestamp('nafath_verified_at')->nullable();
            $table->boolean('is_tester')->default(false);            // passes maintenance mode
            $table->json('notification_prefs')->nullable();          // the six switches of the app's settings
            $table->char('signup_device_hash', 64)->nullable();      // salted HMAC, never the raw id (§14.2)
            $table->char('signup_ip_hash', 64)->nullable();
            $table->string('suspended_reason', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();          // purged after the deletion grace period
            $table->timestamps();

            $table->index(['role', 'status']);
            $table->index('city');
            $table->index(['signup_device_hash', 'created_at']);
            $table->index(['signup_ip_hash', 'created_at']);
        });
        Ddl::in('customers', 'role', ['seeker', 'broker', 'owner', 'developer']);
        Ddl::in('customers', 'status', ['active', 'pending', 'suspended']);
        Ddl::in('customers', 'fal_status', ['none', 'pending', 'valid', 'rejected', 'expired']);
        Ddl::in('customers', 'nafath_status', ['none', 'pending', 'verified', 'rejected']);

        // Push tokens (FCM) of the account's phones.
        Schema::create('customer_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 8);
            $table->string('push_token', 255)->unique();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Account deletion: 30 days of grace; signing in during them cancels it (store requirement).
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('purge_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 40)->nullable();          // App\Support\Actor: customer:12 · staff:3
            $table->timestamp('purged_at')->nullable();
            $table->json('hidden_items')->nullable();                 // what the request hid, restored on cancel
            $table->timestamps();
            $table->index(['customer_id', 'cancelled_at', 'purged_at']);
            $table->index('purge_at');
        });

        // «طلب استعادة» of data saved by app 1.1 without an owner (QA F01): reviewed by staff, never automatic.
        Schema::create('legacy_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('items');                                   // listing and request ids from the device
            $table->string('status', 10)->default('pending');        // pending · accepted · rejected
            $table->text('staff_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
        Ddl::in('legacy_claims', 'status', ['pending', 'accepted', 'rejected']);
    }

    public function down(): void
    {
        foreach (['legacy_claims', 'deletion_requests', 'customer_devices', 'customers', 'search_terms', 'legal_pages',
            'app_configs', 'settings', 'neighborhoods', 'cities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
