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

    public function up(): void
    {
        if (! Schema::hasColumn('school_invoices', 'billing_month')) {
            Schema::table('school_invoices', fn (Blueprint $table) => $table->string('billing_month', 7)->nullable());
        }
        if (! Schema::hasColumn('school_exams', 'schedule_status')) {
            Schema::table('school_exams', fn (Blueprint $table) => $table->string('schedule_status')->default('draft'));
        }
        $this->createTable('school_payroll_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_id')->constrained('school_payroll')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        $this->createTable('school_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key');
            $table->string('module');
            $table->unsignedBigInteger('record_id');
            $table->string('title');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
        });
        $this->createTable('school_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('module');
            $table->unsignedBigInteger('record_id');
            $table->string('event_key')->unique();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_notification_events');
        Schema::dropIfExists('school_notifications');
        Schema::dropIfExists('school_payroll_payments');
        Schema::table('school_invoices', fn (Blueprint $table) => $table->dropColumn('billing_month'));
        Schema::table('school_exams', fn (Blueprint $table) => $table->dropColumn('schedule_status'));
    }
};
