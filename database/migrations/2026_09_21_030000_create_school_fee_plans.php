<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_fee_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->unsignedTinyInteger('due_day')->default(10);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'branch_id', 'class_id'], 'school_fee_plans_class_unique');
            $table->foreign(['school_id', 'branch_id'], 'school_fee_plans_school_branch_foreign')->references(['school_id', 'id'])->on('school_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_fee_plans');
    }
};
