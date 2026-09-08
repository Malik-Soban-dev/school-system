<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique();
            $table->json('roles')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('email')->nullable()->change();
        });
        Schema::create('school_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_settings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'roles', 'is_active']);
        });
    }
};
