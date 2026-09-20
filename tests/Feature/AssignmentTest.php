<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_create_assignments_and_parent_only_sees_published_class_work(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 6', 'year_id' => $year, 'capacity' => 30]);
        $subject = $portal->save('subjects', $owner, ['name' => 'Science', 'code' => 'SCI-6']);
        $student = $portal->save('students', $owner, ['name' => 'Assignment Student', 'admission_number' => 'ASSIGN-1', 'class_id' => $class, 'status' => 'active']);
        $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);

        $published = $this->actingAs($owner)->postJson('/portal/records/assignments', ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $owner->id, 'title' => 'Science worksheet', 'description' => 'Complete questions 1 to 10.', 'due_on' => '2026-09-25', 'status' => 'published'])->assertOk()->json('id');
        $this->actingAs($owner)->postJson('/portal/records/assignments', ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $owner->id, 'title' => 'Draft lesson', 'description' => 'Not released yet.', 'due_on' => '2026-09-26', 'status' => 'draft'])->assertOk();
        $this->actingAs($parent)->getJson('/portal/records/assignments')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $published)->assertJsonPath('rows.0.status', 'published');
        $this->assertDatabaseHas('school_audit', ['module' => 'assignments', 'record_id' => $published, 'action' => 'created']);
    }

    public function test_teacher_cannot_create_an_assignment_for_an_unassigned_subject(): void
    {
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 7', 'year_id' => $year, 'capacity' => 30]);
        $subject = $portal->save('subjects', $owner, ['name' => 'English', 'code' => 'ENG-7']);
        $this->actingAs($teacher)->postJson('/portal/records/assignments', ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $teacher->id, 'title' => 'Essay', 'description' => 'Write an essay.', 'due_on' => '2026-09-25', 'status' => 'draft'])->assertForbidden();
    }
}
