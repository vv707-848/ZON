<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Photos are cleaned at upload (App\Support\Photos\Photos): upright, without metadata, in sizes besides the main
// one. `sizes` lists the extra sizes stored next to `path` ("large", "card"); a row without it has the main only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->json('sizes')->nullable()->after('phash');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('sizes');
        });
    }
};
