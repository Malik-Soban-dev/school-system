<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_invoices', function (Blueprint $table): void {
            $table->string('billing_key')->nullable()->unique()->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_invoices', function (Blueprint $table): void {
            $table->dropUnique(['billing_key']);
            $table->dropColumn('billing_key');
        });
    }
};
