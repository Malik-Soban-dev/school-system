<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild('school_settings', 'school_settings_tenant', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('key');
            $table->text('value');
            $table->primary(['school_id', 'key']);
        }, ['school_id', 'key', 'value']);
        $this->rebuild('school_notification_preferences', 'school_notification_preferences_tenant', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('whatsapp_phone')->nullable();
            $table->timestamp('whatsapp_consented_at')->nullable();
            $table->timestamp('email_consented_at')->nullable();
            $table->timestamps();
            $table->primary(['school_id', 'user_id']);
        }, ['school_id', 'user_id', 'whatsapp_phone', 'whatsapp_consented_at', 'email_consented_at', 'created_at', 'updated_at']);
    }

    private function rebuild(string $source, string $target, callable $definition, array $columns): void
    {
        if (! Schema::hasTable($source) || Schema::hasTable($target)) {
            return;
        }
        Schema::create($target, $definition);
        foreach (DB::table($source)->get() as $row) {
            $data = [];
            foreach ($columns as $column) {
                if (property_exists($row, $column)) {
                    $data[$column] = $row->{$column};
                }
            }
            DB::table($target)->insert($data);
        }
        Schema::drop($source);
        Schema::rename($target, $source);
    }

    public function down(): void
    {
        // Keep tenant key structures during rollback to avoid merging school data.
    }
};
