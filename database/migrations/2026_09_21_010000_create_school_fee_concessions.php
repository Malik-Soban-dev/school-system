<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_fee_concessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->string('name');
            $table->string('type');
            $table->decimal('value', 10, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['school_id', 'branch_id', 'student_id', 'status'], 'school_fee_concessions_scope_index');
            $table->foreign(['school_id', 'branch_id'], 'school_fee_concessions_school_branch_foreign')
                ->references(['school_id', 'id'])->on('school_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_fee_concessions');
    }
};
