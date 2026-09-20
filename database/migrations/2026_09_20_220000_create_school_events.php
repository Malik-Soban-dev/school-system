<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('school_branches')->nullOnDelete();
            $table->string('title'); $table->text('description')->nullable(); $table->date('event_date');
            $table->string('starts_at')->nullable(); $table->string('ends_at')->nullable(); $table->string('location')->nullable();
            $table->string('audience')->default('all'); $table->string('status')->default('draft'); $table->timestamps();
            $table->index(['school_id', 'branch_id', 'event_date']);
        });
        Schema::create('school_event_rsvps', function (Blueprint $table): void {
            $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('school_branches')->nullOnDelete();
            $table->foreignId('event_id')->constrained('school_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); $table->string('response'); $table->timestamps();
            $table->unique(['school_id', 'event_id', 'user_id']); $table->index(['school_id', 'user_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('school_event_rsvps'); Schema::dropIfExists('school_events'); }
};
