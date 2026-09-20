<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_student_welfare')) {
            return;
        }

        Schema::create('school_student_welfare', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('student_id')->constrained('school_students')->cascadeOnDelete();
            $table->string('record_type');
            $table->date('record_date');
            $table->text('details');
            $table->text('follow_up')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'branch_id', 'student_id']);
            $table->index(['school_id', 'record_type', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_student_welfare');
    }
};
