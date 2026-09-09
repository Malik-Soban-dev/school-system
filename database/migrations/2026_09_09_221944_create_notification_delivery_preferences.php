<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function createTable(string $name, Closure $definition): void
    {
        foreach (DB::connection()->pretend(fn () => Schema::create($name, $definition)) as $statement) {
            DB::statement(preg_replace('/^create (table|unique index|index) /i', 'create $1 if not exists ', $statement['query']), $statement['bindings']);
        }
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createTable('school_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('whatsapp_phone')->nullable();
            $table->timestamp('whatsapp_consented_at')->nullable();
            $table->timestamp('email_consented_at')->nullable();
            $table->timestamps();
        });
        $this->createTable('school_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('school_notifications')->cascadeOnDelete();
            $table->string('channel');
            $table->string('status')->default('pending');
            $table->string('provider_id')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_notification_deliveries');
        Schema::dropIfExists('school_notification_preferences');
    }
};
