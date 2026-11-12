<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per operation the app marked with an Idempotency-Key (App\Http\Middleware\Idempotent): the first request
 * runs, and every repeat of it — a retry after a lost answer, or the same tap sent twice at once — gets its answer
 * instead of running again. The unique (customer, key) makes «first» hold under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('fingerprint', 64);                       // method, path and body: the same key means the same operation
            $table->unsignedSmallInteger('status_code')->nullable();  // null while the first request runs
            $table->longText('response')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
