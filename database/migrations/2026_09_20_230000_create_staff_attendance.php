<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_staff_attendance', function (Blueprint $table): void {
            $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('school_branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); $table->date('date'); $table->string('status');
            $table->string('check_in')->nullable(); $table->text('note')->nullable(); $table->timestamps();
            $table->unique(['school_id', 'branch_id', 'user_id', 'date']); $table->index(['school_id', 'branch_id', 'date', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('school_staff_attendance'); }
};
