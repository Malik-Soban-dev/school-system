<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function createTable(string $name, Closure $definition): void
    {
        $statements = DB::connection()->pretend(fn () => Schema::create($name, $definition));
        foreach ($statements as $statement) {
            $sql = preg_replace('/^create (table|unique index|index) /i', 'create $1 if not exists ', $statement['query']);
            DB::statement($sql, $statement['bindings']);
        }
    }

    public function up(): void
    {
        $this->createTable('school_academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
        $this->createTable('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('year_id')->constrained('school_academic_years')->restrictOnDelete();
            $table->unsignedInteger('capacity');
            $table->timestamps();
        });
        $this->createTable('school_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->unique(['code']);
        });
        $this->createTable('school_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('employee_number');
            $table->string('department');
            $table->string('designation');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('joined_on');
            $table->string('status');
            $table->timestamps();
            $table->unique(['employee_number']);
        });
        $this->createTable('school_students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('admission_number');
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->timestamps();
            $table->unique(['admission_number']);
        });
        $this->createTable('school_guardian_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('relationship');
            $table->string('status');
            $table->timestamps();
            $table->unique(['student_id', 'user_id']);
        });
        $this->createTable('school_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->restrictOnDelete();
            $table->string('status');
            $table->timestamps();
            $table->unique(['user_id', 'class_id', 'subject_id']);
        });
        $this->createTable('school_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->date('date');
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'date']);
        });
        $this->createTable('school_timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('weekday');
            $table->string('starts_at');
            $table->string('ends_at');
            $table->string('room');
            $table->timestamps();
        });
        $this->createTable('school_exams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->date('date');
            $table->string('status');
            $table->timestamps();
        });
        $this->createTable('school_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('school_exams')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('school_subjects')->restrictOnDelete();
            $table->decimal('marks', 8, 2);
            $table->decimal('maximum', 8, 2);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id', 'subject_id']);
        });
        $this->createTable('school_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->date('due_on');
            $table->timestamps();
            $table->unique(['reference']);
        });
        $this->createTable('school_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('school_invoices')->restrictOnDelete();
            $table->string('reference');
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['reference']);
        });
        $this->createTable('school_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->string('description');
            $table->string('category');
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->timestamps();
            $table->unique(['reference']);
        });
        $this->createTable('school_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason');
            $table->string('status');
            $table->timestamps();
        });
        $this->createTable('school_payroll', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('school_staff')->restrictOnDelete();
            $table->string('month');
            $table->unsignedBigInteger('basic');
            $table->unsignedBigInteger('allowances');
            $table->unsignedBigInteger('deductions');
            $table->timestamps();
            $table->unique(['staff_id', 'month']);
        });
        $this->createTable('school_notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience');
            $table->string('status');
            $table->timestamps();
        });
        $this->createTable('school_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'class_id']);
        });
        $this->createTable('school_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module');
            $table->unsignedBigInteger('record_id');
            $table->string('action');
            $table->json('changes');
            $table->timestamp('created_at');
        });
        $this->createTable('school_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->json('roles');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        if (! Schema::hasColumn('users', 'tutorials')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('tutorials')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tutorials');
        });
        Schema::dropIfExists('school_invitations');
        Schema::dropIfExists('school_audit');
        Schema::dropIfExists('school_enrollments');
        Schema::dropIfExists('school_notices');
        Schema::dropIfExists('school_payroll');
        Schema::dropIfExists('school_leave_requests');
        Schema::dropIfExists('school_expenses');
        Schema::dropIfExists('school_payments');
        Schema::dropIfExists('school_invoices');
        Schema::dropIfExists('school_grades');
        Schema::dropIfExists('school_exams');
        Schema::dropIfExists('school_timetables');
        Schema::dropIfExists('school_attendance');
        Schema::dropIfExists('school_teacher_assignments');
        Schema::dropIfExists('school_guardian_links');
        Schema::dropIfExists('school_students');
        Schema::dropIfExists('school_staff');
        Schema::dropIfExists('school_subjects');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('school_academic_years');
    }
};
