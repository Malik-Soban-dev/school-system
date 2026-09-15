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
        $schoolId = DB::table('schools')->where('slug', 'default-school')->value('id') ?: DB::table('schools')->orderBy('id')->value('id');
        if (! $schoolId) {
            return;
        }

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'school_id')) {
                continue;
            }
            DB::table($tableName)->whereNull('school_id')->update(['school_id' => $schoolId]);
            if (app()->environment('testing')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('school_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'school_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('school_id')->nullable()->change();
            });
        }
    }
};
