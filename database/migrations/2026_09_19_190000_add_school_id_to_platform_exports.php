<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_exports', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->after('requested_by')->constrained('schools')->nullOnDelete();
            $table->index(['type', 'school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_exports', function (Blueprint $table): void {
            $table->dropForeign(['school_id']);
            $table->dropIndex(['type', 'school_id', 'status']);
            $table->dropColumn('school_id');
        });
    }
};
