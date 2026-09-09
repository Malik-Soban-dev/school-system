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

    private function student(?User $user = null): int
    {
        $year = $this->record('academic_years', ['name' => 'School year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $this->record('classes', ['name' => 'Grade 5 A', 'year_id' => $year, 'capacity' => 30]);

        return $this->record('students', ['name' => fake()->name(), 'admission_number' => fake()->unique()->numerify('ADM-#####'), 'class_id' => $class, 'status' => 'active', 'user_id' => $user?->id]);
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
