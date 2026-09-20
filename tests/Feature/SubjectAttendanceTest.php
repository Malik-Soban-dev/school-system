<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SubjectAttendanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_school_staff_can_record_subject_attendance(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $subject = $portal->save('subjects', $owner, ['name' => 'Mathematics', 'code' => 'MATH-5']);
        $student = $portal->save('students', $owner, ['name' => 'Subject Student', 'admission_number' => 'SUBJECT-1', 'class_id' => $class, 'status' => 'active']);

        $id = $this->actingAs($owner)->postJson('/portal/records/subject_attendance', ['student_id' => $student, 'subject_id' => $subject, 'date' => '2026-09-20', 'status' => 'late', 'note' => 'Arrived after the lesson began.'])->assertOk()->json('id');
        $this->getJson('/portal/records/subject_attendance')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonPath('rows.0.subject_id', $subject)->assertJsonPath('rows.0.status', 'late');
        $this->assertDatabaseHas('school_audit', ['module' => 'subject_attendance', 'record_id' => $id, 'action' => 'created']);
    }

    public function test_subject_attendance_is_not_visible_to_an_unlinked_parent(): void
    {
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $this->actingAs($parent)->getJson('/portal/records/subject_attendance')->assertOk()->assertJsonCount(0, 'rows');
        $this->actingAs($parent)->postJson('/portal/records/subject_attendance', [])->assertForbidden();
    }
}
