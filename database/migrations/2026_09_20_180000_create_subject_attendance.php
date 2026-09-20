<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_subject_attendance')) {
            return;
        }

        Schema::create('school_subject_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('student_id')->constrained('school_students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'student_id', 'subject_id', 'date']);
            $table->index(['school_id', 'branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_subject_attendance');
    }
};
