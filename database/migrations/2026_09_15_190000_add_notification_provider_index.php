<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_notification_deliveries', function (Blueprint $table): void {
            $table->index(['channel', 'provider_id'], 'school_notification_deliveries_channel_provider_index');
        });
    }

    public function down(): void
    {
        Schema::table('school_notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('school_notification_deliveries_channel_provider_index');
        });
    }
};
