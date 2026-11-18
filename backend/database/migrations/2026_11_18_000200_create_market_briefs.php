<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «تقارير المطوّرين»: a market brief made in the panel for a developer (or a buyer outside the platform) and sold to him.
// Its sections are frozen when it is made — what was sold does not change — and it opens on the website by a long
// random link until it expires. Paid by transfer outside the platform: the panel records the amount and the day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_briefs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();   // the developer, when he has an account
            $table->string('buyer_name', 120);
            $table->string('city', 80);
            $table->string('neighborhood', 80)->nullable();
            $table->string('purpose', 4);
            $table->string('property_type', 12)->nullable();
            $table->unsignedSmallInteger('days');
            $table->json('sections');                 // [{report, title, why, columns, rows}] as they ran
            $table->json('highlights');               // the headline numbers, read from the rows
            $table->text('analyst_note')->nullable(); // staff's own words, shown as theirs
            $table->unsignedBigInteger('price_halalas')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->char('token', 40)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('views_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Ddl::in('market_briefs', 'purpose', ['sale', 'rent']);
    }

    public function down(): void
    {
        Schema::dropIfExists('market_briefs');
    }
};
