<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_errors', function (Blueprint $table): void {
            $table->id();
            $table->string('error_type', 160);
            $table->unsignedSmallInteger('status')->default(500);
            $table->string('method', 12)->nullable();
            $table->string('route', 160)->nullable();
            $table->string('fingerprint', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['occurred_at', 'status']);
            $table->index(['fingerprint', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_errors');
    }
};
