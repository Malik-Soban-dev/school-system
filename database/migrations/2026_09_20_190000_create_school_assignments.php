<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_assignments')) {
            return;
        }

        Schema::create('school_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->date('due_on');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['school_id', 'branch_id', 'class_id', 'status']);
            $table->index(['school_id', 'teacher_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_assignments');
    }
};
