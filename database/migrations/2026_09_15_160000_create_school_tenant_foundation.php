<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'school_settings', 'school_academic_years', 'school_classes', 'school_subjects', 'school_staff',
        'school_students', 'school_guardian_links', 'school_teacher_assignments', 'school_attendance',
        'school_timetables', 'school_exams', 'school_grades', 'school_invoices', 'school_payments',
        'school_expenses', 'school_leave_requests', 'school_payroll', 'school_payroll_payments',
        'school_notices', 'school_enrollments', 'school_audit', 'school_invitations', 'school_notification_events',
        'school_notifications', 'school_notification_deliveries', 'school_notification_preferences',
        'school_exam_subjects', 'school_grade_bands',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('schools')) {
            Schema::create('schools', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        $schoolId = DB::table('schools')->where('slug', 'default-school')->value('id');
        if (! $schoolId) {
            $schoolId = DB::table('schools')->insertGetId(['name' => 'Default School', 'slug' => 'default-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (! Schema::hasColumn($tableName, 'school_id')) {
                Schema::table($tableName, fn (Blueprint $table): mixed => $table->unsignedBigInteger('school_id')->nullable()->index());
            }
            DB::table($tableName)->whereNull('school_id')->update(['school_id' => $schoolId]);
        }
        if (! Schema::hasTable('school_user')) {
            Schema::create('school_user', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('active');
                $table->timestamps();
                $table->unique(['school_id', 'user_id']);
            });
        }
        foreach (DB::table('users')->get(['id', 'roles']) as $user) {
            $roles = json_decode((string) $user->roles, true) ?: [];
            if (in_array('superadmin', $roles, true)) {
                continue;
            }
            DB::table('school_user')->insertOrIgnore(['school_id' => $schoolId, 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_user');
        foreach (array_reverse($this->tables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'school_id')) {
                Schema::table($tableName, fn (Blueprint $table): mixed => $table->dropColumn('school_id'));
            }
        }
        Schema::dropIfExists('schools');
    }
};
