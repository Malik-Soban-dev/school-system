<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffAttendanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_attendance_is_unique_per_day_and_teacher_sees_own_history(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $portal->save('staff', $owner, ['name' => 'Teacher One', 'employee_number' => 'STAFF-1', 'department' => 'Teaching', 'designation' => 'Teacher', 'user_id' => $teacher->id, 'joined_on' => '2026-01-01', 'status' => 'active']);

        $id = $this->actingAs($owner)->postJson('/portal/records/staff_attendance', ['user_id' => $teacher->id, 'date' => '2026-09-20', 'status' => 'late', 'check_in' => '08:35', 'note' => 'Traffic'])->assertOk()->json('id');
        $this->actingAs($teacher)->getJson('/portal/records/staff_attendance')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonPath('rows.0.status', 'late');
        $this->actingAs($owner)->postJson('/portal/records/staff_attendance', ['user_id' => $teacher->id, 'date' => '2026-09-20', 'status' => 'present'])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->actingAs($owner)->getJson('/portal/meta')->assertOk()->assertJsonPath('overview.staff_attendance.total', 1)->assertJsonPath('overview.staff_attendance.late', 1)->assertJsonPath('overview.staff_attendance.percentage', 100);
        $this->assertDatabaseHas('school_staff_attendance', ['user_id' => $teacher->id, 'status' => 'late']);
    }
}
