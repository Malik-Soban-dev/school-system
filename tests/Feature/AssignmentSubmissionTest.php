<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AssignmentSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_submit_published_work_and_admin_can_return_feedback(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $studentUser = User::factory()->create(['roles' => ['student'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 6', 'year_id' => $year, 'capacity' => 30]);
        $subject = $portal->save('subjects', $owner, ['name' => 'Science', 'code' => 'SCI-6']);
        $student = $portal->save('students', $owner, ['name' => 'Submitting Student', 'admission_number' => 'SUBMIT-1', 'class_id' => $class, 'user_id' => $studentUser->id, 'status' => 'active']);
        $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $assignment = $portal->save('assignments', $owner, ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $owner->id, 'title' => 'Science worksheet', 'description' => 'Complete the worksheet.', 'due_on' => '2026-09-25', 'status' => 'published']);

        $submission = $this->actingAs($studentUser)->postJson('/portal/records/submissions', ['assignment_id' => $assignment, 'student_id' => $student, 'content' => 'My completed answers.', 'status' => 'submitted'])->assertOk()->json('id');
        $this->artisan('school:notifications')->assertSuccessful();
        $this->actingAs($owner)->getJson('/portal/notifications')->assertOk()->assertJsonFragment(['title' => 'New assignment submission']);
        $this->actingAs($studentUser)->postJson('/portal/records/submissions', ['assignment_id' => $assignment, 'student_id' => $student, 'content' => 'Duplicate answers.', 'status' => 'submitted'])->assertUnprocessable();
        $this->actingAs($parent)->getJson('/portal/records/submissions')->assertOk()->assertJsonPath('rows.0.id', $submission)->assertJsonPath('rows.0.status', 'submitted');
        $this->actingAs($owner)->putJson('/portal/records/submissions/'.$submission, ['assignment_id' => $assignment, 'student_id' => $student, 'content' => 'My completed answers.', 'status' => 'returned', 'grade' => '92.50', 'feedback' => 'Strong work.'])->assertOk();
        $this->artisan('school:notifications')->assertSuccessful();
        $this->actingAs($parent)->getJson('/portal/notifications')->assertOk()->assertJsonFragment(['title' => 'Assignment feedback available']);
        $this->assertDatabaseHas('school_submissions', ['id' => $submission, 'status' => 'returned', 'grade' => 92.5]);
    }
}
