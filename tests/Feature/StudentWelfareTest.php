<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentWelfareTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_school_leadership_can_create_and_view_private_student_welfare_records(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Private Student', 'admission_number' => 'WELFARE-1', 'class_id' => $class, 'status' => 'active']);

        $id = $this->actingAs($owner)->postJson('/portal/records/student_welfare', ['student_id' => $student, 'record_type' => 'medical', 'record_date' => '2026-09-20', 'details' => 'Allergy noted and parent informed.', 'follow_up' => 'Keep medication with the office.'])->assertOk()->json('id');
        $this->getJson('/portal/records/student_welfare')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonPath('rows.0.record_type', 'medical')->assertJsonPath('rows.0.details', 'Allergy noted and parent informed.');
        $this->assertDatabaseHas('school_audit', ['module' => 'student_welfare', 'record_id' => $id, 'action' => 'created']);
    }

    public function test_private_student_welfare_records_are_not_available_to_parents(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $this->actingAs($parent)->getJson('/portal/records/student_welfare')->assertForbidden();
        $this->actingAs($parent)->postJson('/portal/records/student_welfare', [])->assertForbidden();
    }
}
