<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app purchases (App Store · Google Play). Each account has a token the app attaches to its purchases (Apple's
// appAccountToken, Google's obfuscatedExternalAccountId), so a purchase is applied to the account that made it
// only. A store payment keeps its chain (Apple's originalTransactionId, Google's purchaseToken): renewals and
// refunds the stores announce are matched by it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('store_token')->nullable()->unique()->after('is_tester');
        });
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('store_chain', 255)->nullable()->after('provider_ref');
            $table->index(['provider', 'store_chain']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['provider', 'store_chain']);
            $table->dropColumn('store_chain');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['store_token']);
            $table->dropColumn('store_token');
        });
    }
};
