<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_feature_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('feature', 60);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['school_id', 'feature']);
            $table->index(['feature', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_feature_overrides');
    }
};
