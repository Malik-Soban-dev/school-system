<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        ['school_subjects', 'school_subjects_code_unique', ['school_id', 'code']],
        ['school_staff', 'school_staff_employee_number_unique', ['school_id', 'employee_number']],
        ['school_students', 'school_students_admission_number_unique', ['school_id', 'admission_number']],
        ['school_guardian_links', 'school_guardian_links_student_id_user_id_unique', ['school_id', 'student_id', 'user_id']],
        ['school_teacher_assignments', 'school_teacher_assignments_user_id_class_id_subject_id_unique', ['school_id', 'user_id', 'class_id', 'subject_id']],
        ['school_attendance', 'school_attendance_student_id_date_unique', ['school_id', 'student_id', 'date']],
        ['school_grades', 'school_grades_exam_id_student_id_subject_id_unique', ['school_id', 'exam_id', 'student_id', 'subject_id']],
        ['school_invoices', 'school_invoices_reference_unique', ['school_id', 'reference']],
        ['school_payments', 'school_payments_reference_unique', ['school_id', 'reference']],
        ['school_expenses', 'school_expenses_reference_unique', ['school_id', 'reference']],
        ['school_payroll', 'school_payroll_staff_id_month_unique', ['school_id', 'staff_id', 'month']],
        ['school_enrollments', 'school_enrollments_student_id_class_id_unique', ['school_id', 'student_id', 'class_id']],
        ['school_invitations', 'school_invitations_email_unique', ['school_id', 'email']],
        ['school_invitations', 'school_invitations_token_hash_unique', ['school_id', 'token_hash']],
        ['school_notification_deliveries', 'school_notification_deliveries_notification_id_channel_unique', ['school_id', 'notification_id', 'channel']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$tableName, $oldName, $columns]) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($oldName, $columns): void {
                $table->dropUnique($oldName);
                $table->unique($columns, $oldName);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes) as [$tableName, $indexName, $columns]) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($indexName, $columns): void {
                $table->dropUnique($indexName);
                $table->unique(array_slice($columns, 1), $indexName);
            });
        }
    }
};
