<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_scheduler_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('command', 120);
            $table->string('status', 20);
            $table->unsignedInteger('exit_code')->nullable();
            $table->string('error_type', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['command', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_scheduler_runs');
    }
};
