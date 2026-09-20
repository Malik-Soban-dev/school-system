<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $branchTables = [
        'school_user_branches',
        'school_academic_years', 'school_classes', 'school_subjects', 'school_staff',
        'school_students', 'school_guardian_links', 'school_teacher_assignments', 'school_attendance',
        'school_timetables', 'school_exams', 'school_grades', 'school_invoices', 'school_payments',
        'school_expenses', 'school_leave_requests', 'school_payroll', 'school_payroll_payments',
        'school_notices', 'school_enrollments', 'school_invitations', 'school_notification_events',
        'school_notifications', 'school_notification_deliveries', 'school_notification_preferences',
        'school_exam_subjects', 'school_grade_bands', 'school_audit',
    ];

    public function up(): void
    {
        Schema::table('school_branches', function (Blueprint $table): void {
            $table->unique(['school_id', 'id'], 'school_branches_school_id_id_unique');
        });

        foreach ($this->branchTables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'school_id') || ! Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreign(['school_id', 'branch_id'], $tableName.'_school_branch_foreign')
                    ->references(['school_id', 'id'])->on('school_branches')->nullOnDelete();
            });
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement("CREATE TRIGGER IF NOT EXISTS {$tableName}_school_branch_insert BEFORE INSERT ON {$tableName} WHEN NEW.branch_id IS NOT NULL AND (NEW.school_id IS NULL OR NOT EXISTS (SELECT 1 FROM school_branches WHERE id = NEW.branch_id AND school_id = NEW.school_id)) BEGIN SELECT RAISE(ABORT, 'branch does not belong to school'); END");
                DB::statement("CREATE TRIGGER IF NOT EXISTS {$tableName}_school_branch_update BEFORE UPDATE OF school_id, branch_id ON {$tableName} WHEN NEW.branch_id IS NOT NULL AND (NEW.school_id IS NULL OR NOT EXISTS (SELECT 1 FROM school_branches WHERE id = NEW.branch_id AND school_id = NEW.school_id)) BEGIN SELECT RAISE(ABORT, 'branch does not belong to school'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->branchTables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'school_id') && Schema::hasColumn($tableName, 'branch_id')) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::statement("DROP TRIGGER IF EXISTS {$tableName}_school_branch_insert");
                    DB::statement("DROP TRIGGER IF EXISTS {$tableName}_school_branch_update");
                }
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropForeign($tableName.'_school_branch_foreign');
                });
            }
        }
        Schema::table('school_branches', function (Blueprint $table): void {
            $table->dropUnique('school_branches_school_id_id_unique');
        });
    }
};
