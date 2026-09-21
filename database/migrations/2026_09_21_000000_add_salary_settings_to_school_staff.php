<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_staff') || Schema::hasColumn('school_staff', 'basic_salary')) {
            return;
        }

        Schema::table('school_staff', function (Blueprint $table): void {
            $table->unsignedBigInteger('basic_salary')->default(0)->after('status');
            $table->unsignedBigInteger('monthly_allowances')->default(0)->after('basic_salary');
            $table->unsignedBigInteger('monthly_deductions')->default(0)->after('monthly_allowances');
            $table->index(['school_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_staff') || ! Schema::hasColumn('school_staff', 'basic_salary')) {
            return;
        }

        Schema::table('school_staff', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'branch_id', 'status']);
            $table->dropColumn(['basic_salary', 'monthly_allowances', 'monthly_deductions']);
        });
    }
};
