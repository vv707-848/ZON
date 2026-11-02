<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Offers: single properties (listings) and developer projects with their unit models (architecture §10.2, §10.2أ).
// Every field a search filters on is a real typed column. The founding fields that cannot be rebuilt later —
// desc_raw, image_hash, lat/lng, listing_history — exist from the first migration (risk 4 of the architecture).
return new class extends Migration
{
    private const STATUSES = ['draft', 'indexing', 'pending_review', 'published', 'paused', 'inactive', 'sold', 'hidden', 'rejected'];

    private const TEXT_REVIEWS = ['pending', 'ready', 'approved', 'edited', 'rejected', 'auto'];

    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('purpose', 4);                            // sale · rent
            $table->string('property_type', 12);
            $table->string('title', 160);
            $table->string('city', 80);
            $table->string('neighborhood', 80);
            $table->decimal('price', 14, 2);
            $table->boolean('negotiable')->default(false);
            $table->decimal('area_land', 10, 2)->nullable();
            $table->decimal('area_built', 10, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('halls')->nullable();
            $table->unsignedTinyInteger('age')->nullable();          // years
            $table->string('floor_level', 8)->nullable();            // ground · first · second · annex · number
            $table->unsignedTinyInteger('floor_number')->nullable();
            $table->unsignedTinyInteger('floors')->nullable();
            $table->string('occupancy', 8)->nullable();              // families · singles
            $table->string('land_use', 12)->nullable();              // residential · commercial · industrial · mixed
            $table->decimal('annual_income', 14, 2)->nullable();
            $table->boolean('has_deed')->default(false);
            $table->boolean('bank_accepted')->default(false);
            $table->text('desc_raw');                                // the advertiser's own words, kept as written
            $table->text('desc_suggested')->nullable();              // the AI's suggestion, awaiting his decision
            $table->text('desc_clean')->nullable();                  // the approved text — the only one indexed
            $table->json('features')->nullable();
            $table->string('text_review', 10)->nullable();
            $table->timestamp('text_reviewed_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('maps_link', 500)->nullable();
            $table->json('phones')->nullable();                      // contact numbers shown to who may see them
            $table->char('image_hash', 16)->nullable();              // perceptual hash of the cover
            $table->timestamp('featured_until')->nullable();         // «مميز»
            $table->timestamp('urgent_until')->nullable();           // «عاجل»
            $table->timestamp('paused_until')->nullable();
            $table->timestamp('auto_hidden_at')->nullable();       // hidden by reports, not by a person (HandleReport may put it back)
            $table->timestamp('published_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();       // inactive after two weeks without an update
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('index_version')->default(0);
            $table->softDeletes();
            $table->timestamps();

            // Architecture §11: equality columns first, then the range.
            $table->index(['city', 'neighborhood', 'property_type', 'status', 'price'], 'listings_filter');
            $table->index('updated_at');
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'last_activity_at']);
        });
        Ddl::in('listings', 'status', self::STATUSES);
        Ddl::in('listings', 'text_review', self::TEXT_REVIEWS);
        Ddl::in('listings', 'purpose', ['sale', 'rent']);

        // Price and status history: written, never edited (price trend, time to sell).
        Schema::create('listing_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('field', 20);                             // status · price · description
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('actor', 40)->nullable();                 // App\Support\Actor
            $table->timestamp('changed_at');
            $table->index(['listing_id', 'changed_at']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('title', 160);
            $table->string('city', 80);
            $table->string('neighborhood', 80);
            $table->string('property_type', 12);                     // the dominant type; units carry their own
            $table->string('delivery', 8)->default('ready');         // ready · offplan
            $table->string('land_use', 12)->nullable();
            $table->unsignedSmallInteger('total_units');
            $table->text('desc_raw');
            $table->text('desc_suggested')->nullable();
            $table->text('desc_clean')->nullable();
            $table->string('text_review', 10)->nullable();
            $table->timestamp('text_reviewed_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('maps_link', 500)->nullable();
            $table->json('phones')->nullable();
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('auto_hidden_at')->nullable();       // hidden by reports, not by a person (HandleReport may put it back)
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['city', 'neighborhood', 'status'], 'projects_filter');
            $table->index(['developer_id', 'status']);
        });
        Ddl::in('projects', 'status', self::STATUSES);
        Ddl::in('projects', 'text_review', self::TEXT_REVIEWS);
        Ddl::in('projects', 'delivery', ['ready', 'offplan']);

        // One row = one unit MODEL («C1 — 6 available»), indexed on its own description (never merged).
        Schema::create('project_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('model_name', 40);
            $table->string('property_type', 12);
            $table->decimal('area', 10, 2)->nullable();
            $table->decimal('area_land', 10, 2)->nullable();
            $table->decimal('area_built', 10, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('halls')->nullable();
            $table->string('land_use', 12)->nullable();
            $table->decimal('price', 14, 2);                         // one fixed price, never a range
            $table->unsignedSmallInteger('available_count')->default(0);
            $table->string('payment_method', 120)->nullable();
            $table->text('desc_raw');
            $table->text('desc_suggested')->nullable();
            $table->text('desc_clean')->nullable();
            $table->string('text_review', 10)->nullable();
            $table->unsignedInteger('index_version')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'model_name']);
            $table->index(['project_id', 'property_type', 'available_count', 'price'], 'units_available');
        });
        Ddl::in('project_units', 'text_review', self::TEXT_REVIEWS);

        // Advertising licences (REGA): issued for an owner's property, entered and verified for a broker's
        // listing, or one per unit model of a ready project (L01).
        Schema::create('ad_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('licence_number', 40);
            $table->string('qr_url', 500)->nullable();
            $table->string('source', 10);                            // issued · entered
            $table->string('status', 10)->default('pending');        // pending · valid · rejected · expired
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->index('licence_number');
        });
        Ddl::in('ad_licenses', 'status', ['pending', 'valid', 'rejected', 'expired']);
        Ddl::in('ad_licenses', 'source', ['issued', 'entered']);

        // Photos and documents of anything (listings, projects, units, stock items, contracts), on MinIO.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('mediable');                      // null until attached
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->char('phash', 16)->nullable();
            $table->unsignedTinyInteger('sort')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'mediable_type']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable');                           // listing · project
            $table->timestamp('created_at')->nullable();
            $table->unique(['customer_id', 'favoritable_type', 'favoritable_id']);
        });
    }

    public function down(): void
    {
        foreach (['favorites', 'media', 'ad_licenses', 'project_units', 'projects', 'listing_history', 'listings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
