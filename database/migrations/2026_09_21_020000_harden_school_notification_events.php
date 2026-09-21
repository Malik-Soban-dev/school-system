<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_notification_events', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('school_notification_events', function (Blueprint $table): void {
            $table->dropColumn(['attempts', 'last_error', 'available_at']);
        });
    }
};
