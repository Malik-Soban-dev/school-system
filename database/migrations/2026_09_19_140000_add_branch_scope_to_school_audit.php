<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('school_audit', 'branch_id')) {
            Schema::table('school_audit', function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('school_id')->constrained('school_branches')->nullOnDelete();
                $table->index(['school_id', 'branch_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_audit', 'branch_id')) {
            Schema::table('school_audit', function (Blueprint $table): void {
                $table->dropForeign(['branch_id']);
                $table->dropIndex(['school_id', 'branch_id', 'created_at']);
                $table->dropColumn('branch_id');
            });
        }
    }
};
