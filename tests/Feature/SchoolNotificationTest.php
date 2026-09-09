<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_all_roles_receive_notices_but_cannot_read_another_accounts_inbox(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $people = collect(['admin', 'teacher', 'parent', 'student', 'accountant'])->map(fn ($role) => User::factory()->create(['roles' => [$role], 'is_active' => true]));
        $portal = app(SchoolPortal::class);
        $notice = $portal->save('notices', $owner, ['title' => 'School opens', 'body' => 'Welcome to school.', 'audience' => 'all', 'status' => 'published']);
        $this->artisan('school:notifications')->assertSuccessful();
        $this->artisan('school:notifications')->assertSuccessful();
        $this->assertDatabaseCount('school_notifications', 6);
        foreach ($people as $person) {
            $response = $this->actingAs($person)->getJson('/portal/notifications')->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('rows.data.0.title', 'School opens');
            $id = $response->json('rows.data.0.id');
            $this->putJson('/portal/notifications/'.$id.'/read')->assertOk();
            $this->getJson('/portal/notifications')->assertJsonPath('unread', 0);
            $ownerId = DB::table('school_notifications')->where('user_id', $owner->id)->value('id');
            $this->putJson('/portal/notifications/'.$ownerId.'/read')->assertNotFound();
        }
        DB::table('school_notices')->where('id', $notice)->update(['audience' => 'teacher']);
        $this->actingAs($people[2])->getJson('/portal/notifications')->assertJsonPath('rows.total', 0);
        auth()->logout();
        $this->getJson('/portal/notifications')->assertUnauthorized();
    }

    public function test_announced_exam_reminders_do_not_publish_marks_and_guardian_revocation_hides_messages(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 9)->startOfDay());
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $studentUser = User::factory()->create(['roles' => ['student'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Class', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Child', 'admission_number' => 'ADM-1', 'class_id' => $class, 'user_id' => $studentUser->id, 'status' => 'active']);
        $link = $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $subject = $portal->save('subjects', $owner, ['name' => 'Math', 'code' => 'MATH']);
        $exam = $portal->save('exams', $owner, ['name' => 'Math paper', 'class_id' => $class, 'date' => '2026-09-16', 'schedule_status' => 'announced', 'status' => 'draft']);
        $portal->save('grades', $owner, ['exam_id' => $exam, 'student_id' => $student, 'subject_id' => $subject, 'marks' => 90, 'maximum' => 100]);
        $this->artisan('school:notifications')->assertSuccessful();
        $this->artisan('school:notifications')->assertSuccessful();
        $response = $this->actingAs($parent)->getJson('/portal/notifications')->assertJsonPath('unread', 2)->assertJsonPath('rows.data.0.title', 'Exam reminder: 7 days to go');
        $notification = $response->json('rows.data.0.id');
        $this->getJson('/portal/records/exams')->assertJsonCount(1, 'rows');
        $this->getJson('/portal/records/grades')->assertJsonCount(0, 'rows');
        DB::table('school_guardian_links')->where('id', $link)->update(['status' => 'revoked']);
        $this->getJson('/portal/notifications')->assertJsonPath('unread', 0)->assertJsonPath('rows.total', 0);
        $this->putJson('/portal/notifications/'.$notification.'/read')->assertNotFound();
        $this->travel(6)->days();
        $this->artisan('school:notifications')->assertSuccessful();
        $this->assertDatabaseCount('school_notifications', 5);
        DB::table('school_exams')->where('id', $exam)->update(['schedule_status' => 'cancelled']);
        $this->artisan('school:notifications')->assertSuccessful();
        $this->assertDatabaseCount('school_notifications', 5);
    }
}
