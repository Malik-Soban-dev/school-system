<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_linked_parent_can_view_student_360_but_not_private_welfare(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 6', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Profile Student', 'admission_number' => 'PROFILE-1', 'class_id' => $class, 'status' => 'active']);
        $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $portal->save('student_welfare', $owner, ['student_id' => $student, 'record_type' => 'medical', 'record_date' => '2026-09-20', 'details' => 'Private note', 'follow_up' => 'Review']);

        $this->actingAs($parent)->getJson('/portal/students/'.$student.'/360')->assertOk()->assertJsonPath('student.name', 'Profile Student')->assertJsonPath('student.class_name', 'Grade 6')->assertJsonCount(1, 'guardians')->assertJsonMissingPath('welfare');
        $this->actingAs($owner)->getJson('/portal/students/'.$student.'/360')->assertOk()->assertJsonPath('welfare.0.details', 'Private note');
        $this->actingAs($parent)->getJson('/portal/students/99999/360')->assertNotFound();
    }
}
