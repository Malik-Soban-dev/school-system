<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_submissions')) {
            return;
        }

        Schema::create('school_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('assignment_id')->constrained('school_assignments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('school_students')->cascadeOnDelete();
            $table->text('content');
            $table->timestamp('submitted_at')->nullable();
            $table->string('status')->default('submitted');
            $table->decimal('grade', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'assignment_id', 'student_id']);
            $table->index(['school_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_submissions');
    }
};
