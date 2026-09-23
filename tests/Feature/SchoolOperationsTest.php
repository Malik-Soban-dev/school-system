<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function person(string $role): User
    {
        return User::factory()->create(['roles' => [$role], 'is_active' => true]);
    }

    private function record(string $module, array $data): int
    {
        return app(SchoolPortal::class)->save($module, $this->person('owner'), $data);
    }

    public function test_school_owner_can_list_and_register_a_branch_with_owner_access(): void
    {
        $owner = $this->person('owner');
        $enterprise = DB::table('platform_plans')->where('code', 'enterprise')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $enterprise, 'status' => 'active']);

        $this->actingAs($owner)->getJson('/portal/branches')->assertOk()->assertJsonCount(1, 'branches')->assertJsonPath('branches.0.is_default', 1);
        $response = $this->actingAs($owner)->postJson('/portal/branches', ['name' => 'North Campus', 'code' => 'north-campus']);

        $response->assertCreated()->assertJsonPath('branch.name', 'North Campus')->assertJsonPath('branch.code', 'north-campus')->assertJsonPath('branch.is_default', 0);
        $branchId = $response->json('branch.id');
        $this->actingAs($owner)->getJson('/portal/branches')->assertOk()->assertJsonCount(2, 'branches')->assertJsonFragment(['id' => $branchId, 'name' => 'North Campus']);
        $this->assertDatabaseHas('school_audit', ['school_id' => 1, 'branch_id' => $branchId, 'user_id' => $owner->id, 'module' => 'branches', 'action' => 'branch_created']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => 1, 'branch_id' => $branchId, 'user_id' => $owner->id, 'roles' => json_encode(['owner']), 'status' => 'active']);
        $this->actingAs($owner)->getJson('/portal/meta')->assertOk()->assertJsonCount(2, 'branch_overview')->assertJsonFragment(['name' => 'North Campus', 'students' => 0, 'staff' => 0, 'classes' => 0, 'collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'attendance_percentage' => 0]);
    }

    public function test_non_owner_cannot_list_or_register_school_branches(): void
    {
        $admin = $this->person('admin');

        $this->actingAs($admin)->getJson('/portal/branches')->assertForbidden();
        $this->actingAs($admin)->postJson('/portal/branches', ['name' => 'Unauthorized Campus', 'code' => 'unauthorized-campus'])->assertForbidden();
    }

    public function test_owner_branch_overview_separates_expenses_and_payroll_disbursements(): void
    {
        $owner = $this->person('owner');
        $enterprise = DB::table('platform_plans')->where('code', 'enterprise')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $enterprise, 'status' => 'active']);
        $branchId = $this->actingAs($owner)->postJson('/portal/branches', ['name' => 'Finance Campus', 'code' => 'finance-campus'])->assertCreated()->json('branch.id');
        DB::table('school_expenses')->insert(['school_id' => 1, 'branch_id' => $branchId, 'reference' => 'EXP-BRANCH-1', 'description' => 'Supplies', 'category' => 'supplies', 'amount' => 2500, 'paid_on' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()]);
        $staffId = DB::table('school_staff')->insertGetId(['school_id' => 1, 'branch_id' => $branchId, 'name' => 'Finance Teacher', 'employee_number' => 'FIN-STAFF-1', 'department' => 'Teaching', 'designation' => 'Teacher', 'joined_on' => '2026-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $payrollId = DB::table('school_payroll')->insertGetId(['school_id' => 1, 'branch_id' => $branchId, 'staff_id' => $staffId, 'month' => '2026-09', 'basic' => 10000, 'allowances' => 0, 'deductions' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_payroll_payments')->insert(['school_id' => 1, 'branch_id' => $branchId, 'payroll_id' => $payrollId, 'reference' => 'PAY-BRANCH-1', 'amount' => 7000, 'paid_on' => '2026-09-20', 'method' => 'bank', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->getJson('/portal/meta')->assertOk()->assertJsonFragment(['name' => 'Finance Campus', 'expenses' => 2500, 'payroll_disbursed' => 7000]);
    }

    public function test_owner_can_read_a_monthly_branch_financial_statement_but_other_roles_cannot(): void
    {
        $owner = $this->person('owner');
        $branchId = $this->actingAs($owner)->postJson('/portal/branches', ['name' => 'Statement Campus', 'code' => 'statement-campus'])->assertCreated()->json('branch.id');
        $yearId = DB::table('school_academic_years')->insertGetId(['school_id' => 1, 'branch_id' => $branchId, 'name' => 'Statement year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $classId = DB::table('school_classes')->insertGetId(['school_id' => 1, 'branch_id' => $branchId, 'name' => 'Statement class', 'year_id' => $yearId, 'capacity' => 20, 'created_at' => now(), 'updated_at' => now()]);
        $studentId = DB::table('school_students')->insertGetId(['school_id' => 1, 'branch_id' => $branchId, 'name' => 'Statement student', 'admission_number' => 'STAT-STUDENT-1', 'class_id' => $classId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_invoices')->insert(['school_id' => 1, 'branch_id' => $branchId, 'reference' => 'STAT-INV-1', 'student_id' => $studentId, 'description' => 'Tuition', 'amount' => 10000, 'due_on' => '2026-09-10', 'billing_month' => '2026-09', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_payments')->insert(['school_id' => 1, 'branch_id' => $branchId, 'invoice_id' => DB::table('school_invoices')->where('reference', 'STAT-INV-1')->value('id'), 'reference' => 'STAT-PAY-1', 'amount' => 4000, 'paid_on' => '2026-09-12', 'method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_expenses')->insert(['school_id' => 1, 'branch_id' => $branchId, 'reference' => 'STAT-EXP-1', 'description' => 'Supplies', 'category' => 'supplies', 'amount' => 2500, 'paid_on' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->getJson('/portal/branch-financial-statement?branch_id='.$branchId.'&month=2026-09')->assertOk()->assertJsonPath('branch.id', $branchId)->assertJsonPath('period', '2026-09')->assertJsonPath('metrics.billed', 10000)->assertJsonPath('metrics.collected', 4000)->assertJsonPath('metrics.outstanding', 6000)->assertJsonPath('metrics.overdue', 6000)->assertJsonPath('metrics.expenses', 2500)->assertJsonPath('metrics.payroll_disbursed', 0)->assertJsonPath('metrics.receipts', 1)->assertJsonPath('metrics.methods.0.method', 'cash')->assertJsonPath('metrics.methods.0.amount', 4000)->assertJsonPath('metrics.outstanding_invoices', 1)->assertJsonPath('metrics.overdue_invoices', 1)->assertJsonPath('metrics.aging.1_30', 6000);
        $export = $this->actingAs($owner)->get('/portal/branch-financial-statement/export?branch_id='.$branchId.'&month=2026-09')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('School,Branch,Period,Currency', $csv);
        $this->assertStringContainsString('Statement Campus', $csv);
        $this->assertStringContainsString('2026-09', $csv);
        $this->actingAs($this->person('admin'))->getJson('/portal/branch-financial-statement?branch_id='.$branchId.'&month=2026-09')->assertForbidden();
        $this->get('/portal/branch-financial-statement/export?branch_id='.$branchId.'&month=2026-09')->assertForbidden();
    }

    public function test_owner_can_assign_an_existing_school_account_to_a_branch_as_admin(): void
    {
        $owner = $this->person('owner');
        $headmaster = $this->person('teacher');
        $enterprise = DB::table('platform_plans')->where('code', 'enterprise')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $enterprise, 'status' => 'active']);
        DB::table('school_user')->insert(['school_id' => 1, 'user_id' => $headmaster->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $branchId = $this->actingAs($owner)->postJson('/portal/branches', ['name' => 'North Campus', 'code' => 'north-campus'])->assertCreated()->json('branch.id');
        $this->actingAs($owner)->putJson('/portal/branch-access', ['user_id' => $headmaster->id, 'branch_id' => $branchId, 'roles' => ['admin'], 'status' => 'active'])->assertOk();

        $this->assertDatabaseHas('school_user_branches', ['school_id' => 1, 'branch_id' => $branchId, 'user_id' => $headmaster->id, 'roles' => json_encode(['admin']), 'status' => 'active']);
        $this->assertDatabaseHas('school_audit', ['school_id' => 1, 'branch_id' => $branchId, 'user_id' => $owner->id, 'module' => 'branch_access', 'record_id' => $headmaster->id, 'action' => 'branch_access_updated']);
        $this->actingAs($headmaster)->getJson('/portal/contexts')->assertOk()->assertJsonCount(1, 'contexts')->assertJsonPath('contexts.0.branch_id', $branchId)->assertJsonPath('contexts.0.roles.0', 'admin');
    }

    private function student(?User $user = null): int
    {
        $year = $this->record('academic_years', ['name' => 'School year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $this->record('classes', ['name' => 'Grade 5 A', 'year_id' => $year, 'capacity' => 30]);

        return $this->record('students', ['name' => fake()->name(), 'admission_number' => fake()->unique()->numerify('ADM-#####'), 'class_id' => $class, 'status' => 'active', 'user_id' => $user?->id]);
    }

    public function test_active_student_creation_respects_the_platform_plan_limit(): void
    {
        $owner = $this->person('owner');
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);
        $student = $this->student();
        $class = DB::table('school_students')->where('id', $student)->value('class_id');
        DB::table('platform_plans')->where('id', $starter)->update(['max_students' => 1]);

        $this->actingAs($owner)->postJson('/portal/records/students', ['name' => 'Over Limit', 'admission_number' => 'OVER-LIMIT', 'class_id' => $class, 'status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_starter_plan_hides_payroll_from_the_school_portal(): void
    {
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);

        $this->actingAs($this->person('owner'))->getJson('/portal/records/payroll')->assertForbidden();
    }

    public function test_starter_plan_hides_notification_endpoints_from_the_school_portal(): void
    {
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);

        $this->actingAs($this->person('owner'))->getJson('/portal/notifications')->assertForbidden();
        $this->getJson('/portal/notification-preferences')->assertForbidden();
    }

    public function test_superadmin_can_enable_a_plan_feature_for_one_school(): void
    {
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);
        $superadmin = $this->person('superadmin');

        $this->actingAs($superadmin)->putJson('/superadmin/schools/1/features', ['feature' => 'payroll', 'enabled' => true])->assertOk();
        $this->actingAs($this->person('owner'))->getJson('/portal/meta')->assertOk()->assertJsonFragment(['key' => 'payroll']);
    }

    public function test_canceled_subscription_overrides_a_school_feature_grant(): void
    {
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);

        DB::table('school_feature_overrides')->insert([
            'school_id' => 1,
            'feature' => 'payroll',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('school_subscriptions')->where('school_id', 1)->update(['status' => 'canceled']);

        $this->actingAs($this->person('owner'))->getJson('/portal/records/payroll')->assertForbidden();
    }

    public function test_superadmin_can_use_entitled_modules_and_exceed_student_limit(): void
    {
        $superadmin = $this->person('superadmin');
        $starter = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->where('school_id', 1)->update(['plan_id' => $starter, 'status' => 'active']);
        $year = $this->record('academic_years', ['name' => 'Superadmin year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $this->record('classes', ['name' => 'Superadmin class', 'year_id' => $year, 'capacity' => 30]);
        DB::table('platform_plans')->where('id', $starter)->update(['max_students' => 1]);
        $this->actingAs($superadmin)->postJson('/portal/records/students', ['name' => 'Override student', 'admission_number' => 'SUPER-1', 'class_id' => $class, 'status' => 'active'])->assertOk();
        $staff = DB::table('school_staff')->insertGetId(['school_id' => 1, 'branch_id' => null, 'name' => 'Superadmin Staff', 'employee_number' => 'SUPER-STAFF', 'department' => 'Operations', 'designation' => 'Manager', 'joined_on' => '2026-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($superadmin)->postJson('/portal/records/payroll', ['staff_id' => $staff, 'month' => '2026-09', 'basic' => '100.00', 'allowances' => '0.00', 'deductions' => '0.00'])->assertOk();
    }

    public function test_parent_sees_only_linked_students_and_revocation_is_immediate(): void
    {
        $parent = $this->person('parent');
        $child = $this->student();
        $other = $this->student();
        $link = $this->record('guardian_links', ['student_id' => $child, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $this->actingAs($parent)->getJson('/portal/records/students')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $child)->assertJsonMissingPath('rows.0.emergency_contact');
        $this->actingAs($parent)->postJson('/portal/records/attendance', ['student_id' => $other])->assertForbidden();
        DB::table('school_guardian_links')->where('id', $link)->update(['status' => 'revoked']);
        $this->getJson('/portal/records/students')->assertJsonCount(0, 'rows');
    }

    public function test_teacher_can_record_today_only_for_assigned_classes(): void
    {
        $teacher = $this->person('teacher');
        $student = $this->student();
        $other = $this->student();
        $class = DB::table('school_students')->find($student)->class_id;
        $subject = $this->record('subjects', ['name' => 'Math', 'code' => 'MATH']);
        $this->record('teacher_assignments', ['user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'active']);
        $data = ['student_id' => $student, 'date' => today()->toDateString(), 'status' => 'present'];
        $this->actingAs($teacher)->postJson('/portal/records/attendance', $data)->assertOk();
        $this->postJson('/portal/records/attendance', $data)->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->postJson('/portal/records/attendance', [...$data, 'student_id' => $other])->assertForbidden();
        $this->postJson('/portal/records/attendance', [...$data, 'date' => today()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson('/portal/attendance/batch', ['records' => [['student_id' => $student, 'status' => 'absent'], ['student_id' => $other, 'status' => 'present']]])->assertForbidden();
        $this->assertDatabaseHas('school_attendance', ['student_id' => $student, 'status' => 'present']);
        $this->assertDatabaseMissing('school_attendance', ['student_id' => $other]);
        $this->getJson('/portal/attendance/roster?class_id='.$class)->assertOk()->assertJsonCount(1, 'rows');
        $this->putJson('/portal/attendance/batch', ['records' => [['student_id' => $student, 'status' => 'late']]])->assertOk();
        $this->assertDatabaseHas('school_attendance', ['student_id' => $student, 'status' => 'late']);
    }

    public function test_payments_use_exact_cents_reject_overpayment_and_cannot_be_rewritten(): void
    {
        $student = $this->student();
        $invoice = $this->record('invoices', ['student_id' => $student, 'reference' => 'INV-1', 'description' => 'Tuition', 'amount' => '100.10', 'due_on' => today()->toDateString()]);
        $data = ['invoice_id' => $invoice, 'reference' => 'PAY-1', 'amount' => '60.05', 'paid_on' => today()->toDateString(), 'method' => 'cash'];
        $payment = $this->actingAs($this->person('accountant'))->postJson('/portal/records/payments', $data)->assertOk()->json('id');
        $this->assertDatabaseHas('school_payments', ['id' => $payment, 'amount' => 6005]);
        $this->getJson('/portal/records/invoices')->assertJsonPath('rows.0.balance', 4005);
        $this->postJson('/portal/records/payments', [...$data, 'reference' => 'PAY-2', 'amount' => '40.06'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->putJson('/portal/records/payments/'.$payment, $data)->assertForbidden();
        $this->postJson('/portal/records/payments', [...$data, 'reference' => 'PAY-2', 'amount' => '40.05'])->assertOk();
        $this->getJson('/portal/records/invoices')->assertJsonPath('rows.0.balance', 0);
    }

    public function test_unpublished_results_are_private_and_published_results_are_locked(): void
    {
        $studentUser = $this->person('student');
        $student = $this->student($studentUser);
        $class = DB::table('school_students')->find($student)->class_id;
        $subject = $this->record('subjects', ['name' => 'Math', 'code' => 'MATH']);
        $exam = $this->record('exams', ['name' => 'Term 1', 'class_id' => $class, 'date' => today()->toDateString(), 'status' => 'draft']);
        $data = ['exam_id' => $exam, 'student_id' => $student, 'subject_id' => $subject, 'marks' => '85', 'maximum' => '100'];
        $grade = $this->record('grades', $data);
        $this->actingAs($studentUser)->getJson('/portal/records/grades')->assertJsonCount(0, 'rows');
        $this->get('/reports/grades/'.$grade)->assertNotFound();
        DB::table('school_exams')->where('id', $exam)->update(['status' => 'published']);
        $this->getJson('/portal/records/grades')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.percentage', 85);
        $this->get('/reports/grades/'.$grade)->assertOk();
        $this->actingAs($this->person('owner'))->putJson('/portal/records/grades/'.$grade, [...$data, 'marks' => 90])->assertUnprocessable()->assertJsonValidationErrors('exam_id');
        $nextClassStudent = $this->student();
        $newClass = DB::table('school_students')->find($nextClassStudent)->class_id;
        $current = (array) DB::table('school_students')->find($student);
        $this->putJson('/portal/records/students/'.$student, [...$current, 'class_id' => $newClass])->assertOk();
        $this->actingAs($studentUser)->getJson('/portal/records/exams')->assertJsonPath('rows.0.id', $exam);
        $this->get('/reports/grades/'.$grade)->assertOk();
        DB::table('school_exams')->where('id', $exam)->update(['status' => 'draft']);
        $this->actingAs($this->person('owner'))->putJson('/portal/records/grades/'.$grade, [...$data, 'marks' => 90])->assertOk();
        $this->assertDatabaseHas('school_enrollments', ['student_id' => $student, 'class_id' => $class]);
        $this->assertDatabaseHas('school_enrollments', ['student_id' => $student, 'class_id' => $newClass]);
    }

    public function test_owner_can_promote_active_students_and_preserve_enrollment_history(): void
    {
        $owner = $this->person('owner');
        $year = $this->record('academic_years', ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $from = $this->record('classes', ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $to = $this->record('classes', ['name' => 'Grade 6', 'year_id' => $year, 'capacity' => 30]);
        $first = $this->record('students', ['name' => 'First Student', 'admission_number' => 'PROMOTE-1', 'class_id' => $from, 'status' => 'active']);
        $second = $this->record('students', ['name' => 'Second Student', 'admission_number' => 'PROMOTE-2', 'class_id' => $from, 'status' => 'active']);

        $this->actingAs($owner)->postJson('/portal/promotions', ['student_ids' => [$first, $second], 'class_id' => $to])->assertOk()->assertJsonPath('promoted', 2);

        $this->assertDatabaseHas('school_students', ['id' => $first, 'class_id' => $to]);
        $this->assertDatabaseHas('school_students', ['id' => $second, 'class_id' => $to]);
        $this->assertDatabaseHas('school_enrollments', ['student_id' => $first, 'class_id' => $from]);
        $this->assertDatabaseHas('school_enrollments', ['student_id' => $first, 'class_id' => $to]);
        $this->assertDatabaseHas('school_audit', ['record_id' => $first, 'action' => 'promoted']);
    }

    public function test_teacher_parent_combined_role_does_not_expose_other_families_invoices(): void
    {
        $teacher = $this->person('teacher');
        $teacher->forceFill(['roles' => ['teacher', 'parent']])->save();
        $student = $this->student();
        $class = DB::table('school_students')->find($student)->class_id;
        $subject = $this->record('subjects', ['name' => 'Math', 'code' => 'MATH']);
        $this->record('teacher_assignments', ['user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'active']);
        $this->record('invoices', ['student_id' => $student, 'reference' => 'PRIVATE', 'description' => 'Tuition', 'amount' => '100', 'due_on' => today()->toDateString()]);
        $this->actingAs($teacher)->getJson('/portal/records/invoices')->assertJsonCount(0, 'rows');
    }

    public function test_combined_teacher_parent_role_keeps_draft_results_within_teaching_assignments(): void
    {
        $teacher = $this->person('teacher');
        $teacher->forceFill(['roles' => ['teacher', 'parent']])->save();
        $pupil = $this->student();
        $child = $this->student();
        $class = DB::table('school_students')->find($pupil)->class_id;
        $childClass = DB::table('school_students')->find($child)->class_id;
        $math = $this->record('subjects', ['name' => 'Math', 'code' => 'M']);
        $english = $this->record('subjects', ['name' => 'English', 'code' => 'E']);
        $assignment = $this->record('teacher_assignments', ['user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $math, 'status' => 'active']);
        $this->record('guardian_links', ['student_id' => $child, 'user_id' => $teacher->id, 'relationship' => 'Parent', 'status' => 'active']);
        $exam = $this->record('exams', ['name' => 'Class exam', 'class_id' => $class, 'date' => today()->toDateString(), 'status' => 'draft']);
        $childExam = $this->record('exams', ['name' => 'Child exam', 'class_id' => $childClass, 'date' => today()->toDateString(), 'status' => 'draft']);
        $grade = $this->record('grades', ['student_id' => $pupil, 'exam_id' => $exam, 'subject_id' => $math, 'marks' => 80, 'maximum' => 100]);
        $this->record('grades', ['student_id' => $pupil, 'exam_id' => $exam, 'subject_id' => $english, 'marks' => 70, 'maximum' => 100]);
        $this->record('grades', ['student_id' => $child, 'exam_id' => $childExam, 'subject_id' => $math, 'marks' => 90, 'maximum' => 100]);
        $this->actingAs($teacher)->getJson('/portal/records/grades')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $grade);
        $this->getJson('/portal/records/exams')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $exam);
        DB::table('school_exams')->where('id', $childExam)->update(['status' => 'published']);
        $this->getJson('/portal/records/grades')->assertJsonCount(2, 'rows');
        DB::table('school_teacher_assignments')->where('id', $assignment)->update(['status' => 'ended']);
        $this->getJson('/portal/records/grades')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.student_id', $child);
    }

    public function test_parent_student_list_has_a_bounded_database_query_count(): void
    {
        $parent = $this->person('parent');
        $child = $this->student();
        $this->record('guardian_links', ['student_id' => $child, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(SchoolPortal::class)->listing('students', $parent);
            $this->assertSame($child, $result['rows'][0]['id']);
            $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_invitation_cannot_grant_owner_and_is_single_use(): void
    {
        $owner = $this->person('owner');
        $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'New person', 'email' => 'person@example.test', 'roles' => ['owner']])->assertUnprocessable();
        $url = $this->postJson('/portal/invitations', ['name' => 'New person', 'email' => 'person@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        auth()->logout();
        $this->post($url, ['username' => 'new.teacher', 'password' => 'Test-only-12345', 'password_confirmation' => 'Test-only-12345', 'roles' => ['owner']])->assertRedirect('/login');
        $this->assertSame(['teacher'], User::where('username', 'new.teacher')->firstOrFail()->roles);
        $this->get($url)->assertNotFound();
    }

    public function test_admin_cannot_change_owner_or_assign_admin_and_tutorials_persist(): void
    {
        $admin = $this->person('admin');
        $owner = $this->person('owner');
        $teacher = $this->person('teacher');
        $this->actingAs($admin)->putJson('/portal/users/'.$owner->id, ['roles' => ['teacher'], 'is_active' => false])->assertForbidden();
        $this->putJson('/portal/users/'.$teacher->id, ['roles' => ['admin'], 'is_active' => true])->assertUnprocessable();
        $this->putJson('/portal/tutorial', ['module' => 'attendance'])->assertOk();
        $this->getJson('/portal/meta')->assertJsonPath('user.tutorials.0', 'attendance');
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => true]);
    }
}
