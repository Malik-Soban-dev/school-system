<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_relation_validation_and_attendance_roster_cannot_cross_school_boundaries(): void
    {
        $schoolOne = DB::table('schools')->where('slug', 'default-school')->value('id');
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Second School', 'slug' => 'second-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        DB::table('school_user')->insert([
            ['school_id' => $schoolOne, 'user_id' => $owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $schoolTwo, 'user_id' => $teacher->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $schoolOne, 'name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $schoolTwo, 'name' => 'Other class', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $subject = DB::table('school_subjects')->insertGetId(['school_id' => $schoolOne, 'name' => 'Math', 'code' => 'MATH', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->postJson('/portal/records/teacher_assignments', [
            'user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAs($owner)->getJson('/portal/attendance/roster?class_id='.$class)
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');
    }
}
