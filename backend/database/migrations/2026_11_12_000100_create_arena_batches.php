<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «ساحة الاختبار» (App\Arena\TestArena): sample listings, requests and wishes made in one batch by the superadmin,
// owned by the batch's own sandbox accounts (customers.is_tester), so no real user ever sees them — and removed
// with one button, every row and every index point. The batch is the record of what it made.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_batches', function (Blueprint $table) {
            $table->id();
            $table->string('city', 80);
            $table->json('neighborhoods');
            $table->unsignedSmallInteger('listings');
            $table->unsignedSmallInteger('requests');
            $table->unsignedSmallInteger('wishes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('arena_batch')->nullable()->after('is_tester')->constrained('arena_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['arena_batch']);
            $table->dropColumn('arena_batch');
        });
        Schema::dropIfExists('arena_batches');
    }
};
