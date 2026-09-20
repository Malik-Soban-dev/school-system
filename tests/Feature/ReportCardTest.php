<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_weighted_report_is_complete_only_with_all_marks_and_preserves_published_grading(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent', 'teacher'], 'is_active' => true]);
        $other = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $save = fn (string $module, array $data): int => app(SchoolPortal::class)->save($module, $owner, $data);
        $year = $save('academic_years', ['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $save('classes', ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $student = $save('students', ['name' => '<script>alert(1)</script>', 'admission_number' => 'ADM-1', 'class_id' => $class, 'status' => 'active']);
        $link = $save('guardian_links', ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $math = $save('subjects', ['name' => 'Mathematics', 'code' => 'MATH']);
        $english = $save('subjects', ['name' => 'English', 'code' => 'ENG']);
        $examData = ['name' => 'Term test', 'class_id' => $class, 'date' => '2026-09-20', 'schedule_status' => 'announced', 'status' => 'draft'];
        $exam = $save('exams', $examData);
        $band = $save('grade_bands', ['name' => 'A', 'minimum' => 80, 'gpa' => 4]);
        $save('grade_bands', ['name' => 'B', 'minimum' => 60, 'gpa' => 3]);
        $save('grade_bands', ['name' => 'F', 'minimum' => 0, 'gpa' => 0]);
        $plan = $save('exam_subjects', ['exam_id' => $exam, 'subject_id' => $math, 'maximum' => 100, 'weight' => 3]);
        $save('exam_subjects', ['exam_id' => $exam, 'subject_id' => $english, 'maximum' => 50, 'weight' => 1]);
        $save('grades', ['exam_id' => $exam, 'subject_id' => $math, 'student_id' => $student, 'marks' => 80, 'maximum' => 100]);
        $url = '/report-cards/'.$exam.'/'.$student;
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Incomplete report')->assertDontSee('Weighted overall result:');
        $this->actingAs($parent)->get($url)->assertNotFound();
        $save('grades', ['exam_id' => $exam, 'subject_id' => $english, 'student_id' => $student, 'marks' => 30, 'maximum' => 50]);
        $save('attendance', ['student_id' => $student, 'date' => '2026-09-01', 'status' => 'present']);
        $save('attendance', ['student_id' => $student, 'date' => '2026-09-02', 'status' => 'late']);
        $save('attendance', ['student_id' => $student, 'date' => '2026-09-03', 'status' => 'absent']);
        $save('attendance', ['student_id' => $student, 'date' => '2026-09-04', 'status' => 'excused']);
        $this->actingAs($owner)->putJson('/portal/records/exams/'.$exam, [...$examData, 'status' => 'published'])->assertOk();
        $this->putJson('/portal/records/grade_bands/'.$band, ['name' => 'Changed', 'minimum' => 80, 'gpa' => 1])->assertOk();
        $this->actingAs($parent)->get($url)->assertOk()->assertSee('75.00%')->assertSee('3.75')->assertSee('Overall grade: B')->assertSee('50.00%')->assertSee('Present / late')->assertDontSee('Changed')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($owner)->putJson('/portal/records/exam_subjects/'.$plan, ['exam_id' => $exam, 'subject_id' => $math, 'maximum' => 100, 'weight' => 2])->assertUnprocessable();
        DB::table('school_guardian_links')->where('id', $link)->update(['status' => 'revoked']);
        $save('teacher_assignments', ['user_id' => $parent->id, 'class_id' => $class, 'subject_id' => $math, 'status' => 'active']);
        $this->actingAs($parent)->get($url)->assertNotFound();
    }

    public function test_grade_thresholds_and_planned_marks_are_validated(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $this->actingAs($owner)->postJson('/portal/records/grade_bands', ['name' => 'Invalid', 'minimum' => 101, 'gpa' => 4])->assertUnprocessable();
        $this->postJson('/portal/records/grade_bands', ['name' => 'A', 'minimum' => 80, 'gpa' => 4])->assertOk();
        $this->postJson('/portal/records/grade_bands', ['name' => 'Duplicate', 'minimum' => 80, 'gpa' => 3])->assertUnprocessable();
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $this->actingAs($teacher)->postJson('/portal/records/grade_bands', ['name' => 'F', 'minimum' => 0, 'gpa' => 0])->assertForbidden();
        $this->get('/report-cards/1/1')->assertForbidden();
    }
}
