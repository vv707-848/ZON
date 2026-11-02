<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The broker's desk (clients, owners, tasks, private stock — architecture §10.8, zero AI but the summary button)
// and everything between accounts: follows, ratings, conversations, blocks, reports, support tickets, notifications.
return new class extends Migration
{
    private const NOTE_CATEGORIES = ['interested', 'rejected', 'cash', 'offered', 'followup', 'closed'];

    public function up(): void
    {
        // A broker's (or developer's) client. Every query carries broker_id = the signed-in account (isolation rule ①).
        Schema::create('crm_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 16)->nullable();                 // empty when added from a request (contact stays in the chat)
            $table->string('status', 10)->default('active');         // active · closed · postponed
            $table->string('purpose', 4)->nullable();
            $table->string('city', 80);
            $table->json('neighborhoods')->nullable();
            $table->json('property_types')->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->decimal('area_min', 10, 2)->nullable();
            $table->unsignedTinyInteger('rooms_min')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('source_request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->timestamps();
            $table->unique(['broker_id', 'phone']);
            $table->index(['city', 'status', 'broker_id'], 'clients_filter');
        });
        Ddl::in('crm_clients', 'status', ['active', 'closed', 'postponed']);
        Ddl::jsonArrayIndex('crm_clients', 'neighborhoods');

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->string('category', 10);
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['client_id', 'created_at']);
        });
        Ddl::in('client_notes', 'category', self::NOTE_CATEGORIES);

        Schema::create('property_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 16);
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('listing_title', 160)->nullable();
            $table->timestamps();
            $table->unique(['broker_id', 'phone']);
        });

        Schema::create('owner_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('property_owners')->cascadeOnDelete();
            $table->string('category', 10);
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['owner_id', 'created_at']);
        });
        Ddl::in('owner_notes', 'category', self::NOTE_CATEGORIES);

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('priority', 6)->default('medium');       // high · medium · low
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'done_at']);
            $table->index(['remind_at', 'reminded_at']);
        });
        Ddl::in('tasks', 'priority', ['high', 'medium', 'low']);

        // Private stock: never indexed, never in any public search (a separate table on purpose).
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('customers')->cascadeOnDelete();
            $table->string('purpose', 4);
            $table->string('property_type', 12);
            $table->string('title', 160);
            $table->string('city', 80);
            $table->string('neighborhood', 80);
            $table->decimal('price', 14, 2)->nullable();
            $table->decimal('area_land', 10, 2)->nullable();
            $table->decimal('area_built', 10, 2)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('published_listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->timestamps();
            $table->index('broker_id');
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['follower_id', 'followed_id']);
            $table->index('followed_id');
        });

        // Stars on a public profile (one per pair, editable); staff may hide one.
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rater_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('rated_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->string('comment', 500)->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->unique(['rater_id', 'rated_id']);
            $table->index('rated_id');
        });
        Ddl::between('ratings', 'stars', 1, 5);

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('hidden_at')->nullable();              // hidden from this side (account deletion)
            $table->unique(['conversation_id', 'customer_id']);
            $table->index('customer_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('customers')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'id']);
        });

        // The user's decision alone; applied in the queries; the blocked account is never told.
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        // Reports of content or accounts; three from different accounts hide the target until staff decide.
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('customers')->cascadeOnDelete();
            $table->morphs('target');                                // listing · project · customer · message · rating
            $table->string('reason', 16)->default('other');
            $table->text('details');
            $table->string('status', 10)->default('new');            // new · reviewing · accepted · rejected
            $table->string('action_taken', 10)->nullable();          // ignored · warned · hidden · banned
            $table->text('staff_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->unique(['reporter_id', 'target_type', 'target_id']);
            $table->index(['status', 'created_at']);
        });
        Ddl::in('content_reports', 'reason', ['misleading', 'sold', 'duplicate', 'bad_licence', 'inappropriate', 'fraud', 'other']);
        Ddl::in('content_reports', 'status', ['new', 'reviewing', 'accepted', 'rejected']);

        // «تذكرة دعم» from the app's settings: a reference number, then the staff reply.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 16)->nullable();
            $table->text('body');
            $table->string('status', 10)->default('open');           // open · answered · closed
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Ddl::in('support_tickets', 'status', ['open', 'answered', 'closed']);

        // The bell (Laravel database notifications) — the app's NoteKind is data.kind.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Staff announcements to all the app's users or a role / city.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('body', 500);
            $table->string('audience', 12)->default('all');          // all · seeker · broker · owner · developer
            $table->string('city', 80)->nullable();
            $table->string('target', 120)->nullable();               // the app route a tap opens
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['announcements', 'notifications', 'support_tickets', 'content_reports', 'user_blocks', 'messages',
            'conversation_participants', 'conversations', 'ratings', 'follows', 'inventory_items', 'tasks',
            'owner_notes', 'property_owners', 'client_notes', 'crm_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
