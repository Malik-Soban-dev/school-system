<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_timetables') || Schema::hasColumn('school_timetables', 'substitute_teacher_id')) {
            return;
        }

        Schema::table('school_timetables', function (Blueprint $table): void {
            $table->foreignId('substitute_teacher_id')->nullable()->after('teacher_id')->constrained('users')->restrictOnDelete();
            $table->index(['school_id', 'branch_id', 'substitute_teacher_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_timetables') || ! Schema::hasColumn('school_timetables', 'substitute_teacher_id')) {
            return;
        }

        Schema::table('school_timetables', function (Blueprint $table): void {
            $table->dropForeign(['substitute_teacher_id']);
            $table->dropIndex(['school_id', 'branch_id', 'substitute_teacher_id']);
            $table->dropColumn('substitute_teacher_id');
        });
    }
};
