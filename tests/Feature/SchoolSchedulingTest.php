<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolSchedulingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_overlapping_lesson_is_rejected_but_adjacent_lesson_is_allowed(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $this->actingAs($owner);
        $year = $this->postJson('/portal/records/academic_years', ['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->assertOk()->json('id');
        $class = $this->postJson('/portal/records/classes', ['name' => 'Class A', 'year_id' => $year, 'capacity' => 20])->assertOk()->json('id');
        $subject = $this->postJson('/portal/records/subjects', ['name' => 'Math', 'code' => 'M'])->assertOk()->json('id');
        $this->postJson('/portal/records/teacher_assignments', ['user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'active'])->assertOk();
        $lesson = ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $teacher->id, 'weekday' => 'Monday', 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => 'Room 1'];
        $this->postJson('/portal/records/timetables', $lesson)->assertOk();
        $this->postJson('/portal/records/timetables', [...$lesson, 'starts_at' => '09:30', 'ends_at' => '10:30'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/portal/records/timetables', [...$lesson, 'starts_at' => '10:00', 'ends_at' => '11:00'])->assertOk();
        $this->assertDatabaseCount('school_timetables', 2);
    }

    public function test_staff_cannot_approve_own_leave_or_request_leave_for_another_user(): void
    {
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $other = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $data = ['user_id' => $teacher->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'reason' => 'Personal leave', 'status' => 'pending'];
        $id = $this->actingAs($teacher)->postJson('/portal/records/leave_requests', $data)->assertOk()->json('id');
        $this->putJson('/portal/records/leave_requests/'.$id, [...$data, 'status' => 'approved'])->assertUnprocessable();
        $this->postJson('/portal/records/leave_requests', [...$data, 'user_id' => $other->id])->assertForbidden();
        $this->actingAs($other)->getJson('/portal/records/leave_requests')->assertJsonCount(0, 'rows');
        $this->assertDatabaseHas('school_leave_requests', ['id' => $id, 'status' => 'pending']);
    }

    public function test_payroll_net_is_exact_and_duplicate_month_is_rejected(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $this->actingAs($owner);
        $staff = $this->postJson('/portal/records/staff', ['name' => 'Staff Member', 'employee_number' => 'EMP-1', 'department' => 'Academic', 'designation' => 'Teacher', 'joined_on' => '2026-01-01', 'status' => 'active'])->assertOk()->json('id');
        $data = ['staff_id' => $staff, 'month' => '2026-09', 'basic' => '1000.10', 'allowances' => '50.05', 'deductions' => '10.01'];
        $this->postJson('/portal/records/payroll', $data)->assertOk();
        $this->getJson('/portal/records/payroll')->assertJsonPath('rows.0.net', 104014);
        $this->postJson('/portal/records/payroll', $data)->assertUnprocessable();
        $this->postJson('/portal/records/payroll', [...$data, 'month' => '2026-10', 'deductions' => '2000'])->assertUnprocessable()->assertJsonValidationErrors('deductions');
        $this->assertDatabaseCount('school_payroll', 1);
    }

    public function test_current_school_date_uses_configured_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-09 02:00:00', 'UTC'));
        DB::table('school_settings')->insert(['key' => 'timezone', 'value' => 'America/Chicago']);
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $this->actingAs($owner)->getJson('/portal/meta')->assertJsonPath('today', '2026-09-08');
    }
}
