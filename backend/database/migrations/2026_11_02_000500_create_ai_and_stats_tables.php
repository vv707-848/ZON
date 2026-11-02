<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the AI layer records and reads (no personal text: hashes and masked text only — §13), the index outbox,
// the capture layer (§10.9), the SuperAgent's archive, and the daily snapshots market reports are built from.
return new class extends Migration
{
    public function up(): void
    {
        // One row per AI call: cost, latency, outcome — and the trace id the agents service logged too.
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent', 24);
            $table->string('status', 10);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->char('request_hash', 64);
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('trace_id');
            $table->index('created_at');
            $table->index(['agent', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
        Ddl::in('ai_requests', 'status', ['ok', 'cached', 'degraded', 'fallback', 'timeout', 'rejected']);

        // Stored summaries: the same notes (same fingerprint) are never summarized — nor charged — twice.
        Schema::create('note_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 8);                              // client · owner · tasks
            $table->unsignedBigInteger('ref_id');                    // client id · owner id · customer id (tasks)
            $table->text('summary');
            $table->json('signals')->nullable();
            $table->text('recommended_action')->nullable();
            $table->string('confidence', 8)->nullable();
            $table->char('source_hash', 40);
            $table->timestamps();
            $table->unique(['scope', 'ref_id']);
        });
        Ddl::in('note_summaries', 'scope', ['client', 'owner', 'tasks']);

        // «مطابق / غير مطابق» under each result; the reason is required for «غير مطابق».
        Schema::create('search_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('context', 10);                           // search · matching · requests
            $table->string('result_type', 14);                       // listing · project_unit · request · searcher
            $table->unsignedBigInteger('result_id');
            $table->boolean('is_match');
            $table->text('reason')->nullable();
            $table->json('query_snapshot');                          // the fields and the masked free text
            $table->timestamp('created_at')->nullable();
            $table->index(['result_type', 'result_id', 'is_match']);
            $table->index('created_at');
        });

        // Searches that found nothing — the earliest unmet-demand signal (search_no_results).
        Schema::create('search_misses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context', 10);
            $table->string('city', 80);
            $table->string('neighborhood', 80)->nullable();
            $table->string('property_type', 12)->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->text('free_text')->nullable();                   // masked
            $table->timestamp('created_at')->nullable();
            $table->index(['city', 'created_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->char('lang', 2);
            $table->string('intent', 16);
            $table->text('question');                                // masked
            $table->text('answer');
            $table->json('kb_doc_ids')->nullable();
            $table->string('guard_status', 10);                      // passed · replaced
            $table->string('ai_status', 10);
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'created_at']);
            $table->index(['guard_status', 'created_at']);
        });

        // The capture layer: de-identified at the source, never linked back to the account (§10.9).
        Schema::create('training_corpus', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);                            // client_note · owner_note · task · chat · feedback · text_edit · search_miss
            $table->text('text_masked');
            $table->string('intent_tag', 30)->nullable();
            $table->string('outcome_tag', 30)->nullable();
            $table->timestamp('captured_at');
            $table->index(['source', 'captured_at']);
        });

        // The chat's knowledge library: versioned, reviewed by an owner, out of retrieval once overdue.
        Schema::create('kb_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('category', 40);
            $table->char('lang', 2)->default('ar');
            $table->string('version', 20);
            $table->date('effective_date');
            $table->string('source', 200);
            $table->string('review_owner', 120);
            $table->date('next_review');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('index_version')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'next_review']);
        });

        // The index outbox (backend doc §10): the signal is committed with the change, then sent and acknowledged.
        // sent_at empty = the backend did not send · acked_at empty after a minute = the index did not take it.
        Schema::create('index_events', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 14);                            // listing · project_unit · request · kb_document
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 6);                             // create · update · delete
            $table->unsignedInteger('version');
            $table->json('payload')->nullable();
            $table->char('payload_hash', 40);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('trace_id', 64)->nullable();              // the action that caused it — kept on every resend
            $table->timestamp('created_at')->nullable();
            $table->unique(['entity', 'entity_id', 'version']);
            $table->index(['acked_at', 'created_at']);
            $table->index('trace_id');
        });
        Ddl::in('index_events', 'entity', ['listing', 'project_unit', 'request', 'kb_document']);
        Ddl::in('index_events', 'action', ['create', 'update', 'delete']);

        // The SuperAgent's reports (question → approved template + params → rows → analysis).
        Schema::create('exec_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('question', 500);
            $table->string('report', 60);
            $table->json('params');
            $table->json('rows');
            $table->unsignedInteger('row_count');
            $table->text('analysis')->nullable();
            $table->json('recommendations')->nullable();
            $table->string('status', 12);                            // ok · no_analysis · failed
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['staff_id', 'created_at']);
        });

        // One row per day, neighbourhood and type — market reports never read the live tables later (§11).
        Schema::create('daily_area_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('city', 80);
            $table->string('neighborhood', 80);
            $table->string('property_type', 12);
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedInteger('listings_count')->default(0);
            $table->decimal('median_budget', 14, 2)->nullable();
            $table->decimal('median_price', 14, 2)->nullable();
            $table->decimal('rejection_rate', 5, 4)->nullable();
            $table->unique(['stat_date', 'city', 'neighborhood', 'property_type'], 'daily_area_stats_key');
        });

        // The admin dashboard's daily counters (signups per role, listings published, revenue, AI calls…).
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('metric', 40);
            $table->string('dimension', 40)->default('');
            $table->decimal('value', 16, 2);
            $table->unique(['stat_date', 'metric', 'dimension']);
        });
    }

    public function down(): void
    {
        foreach (['daily_metrics', 'daily_area_stats', 'exec_reports', 'index_events', 'kb_documents', 'training_corpus',
            'chat_messages', 'search_misses', 'search_feedback', 'note_summaries', 'ai_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
