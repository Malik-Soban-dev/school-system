<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('monthly_price_cents')->default(0);
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_students')->nullable();
            $table->json('features')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['status', 'code']);
        });

        Schema::create('school_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained('schools')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('platform_plans')->restrictOnDelete();
            $table->string('status')->default('trialing');
            $table->timestamp('starts_at');
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'renews_at']);
        });

        $now = now();
        DB::table('platform_plans')->insert([
            ['code' => 'starter', 'name' => 'Starter', 'monthly_price_cents' => 4900, 'max_branches' => 1, 'max_students' => 250, 'features' => json_encode(['attendance', 'grades', 'invoices']), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'growth', 'name' => 'Growth', 'monthly_price_cents' => 14900, 'max_branches' => 5, 'max_students' => 1500, 'features' => json_encode(['attendance', 'grades', 'invoices', 'payroll', 'notifications']), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'monthly_price_cents' => 0, 'max_branches' => null, 'max_students' => null, 'features' => json_encode(['*']), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $growth = DB::table('platform_plans')->where('code', 'growth')->value('id');
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::table('school_subscriptions')->insertOrIgnore(['school_id' => $schoolId, 'plan_id' => $growth, 'status' => 'trialing', 'starts_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_subscriptions');
        Schema::dropIfExists('platform_plans');
    }
};
