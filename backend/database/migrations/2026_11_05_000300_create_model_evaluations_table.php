<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «مختبر النماذج»: one row per run of `zon-agents lab run` uploaded to the panel (docs/model-lab.md). Runs compare
// only on the same dataset (its digest); the whole report is kept, every answer included, for the failing cases.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('profile', 64);                          // agents/zon_agents/models/<profile>.toml
            $table->string('weights', 200);
            $table->string('runtime', 16);                          // vllm · sglang · escha
            $table->string('gpu', 40)->nullable();
            $table->string('notes', 500)->nullable();
            $table->char('dataset_digest', 16);
            $table->unsignedInteger('dataset_cases');
            $table->decimal('overall', 5, 4);
            $table->json('agents');                                 // per agent: score, metrics, failed, latency, variants
            $table->json('throughput');
            $table->unsignedInteger('failed');                      // calls with no usable answer, every agent
            $table->timestamp('started_at');
            $table->decimal('duration_s', 9, 1);
            $table->json('report');                                 // the report as written, outcomes included
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['profile', 'started_at', 'dataset_digest']);
            $table->index(['dataset_digest', 'overall']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_evaluations');
    }
};
