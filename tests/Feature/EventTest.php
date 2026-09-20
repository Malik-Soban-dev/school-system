<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_published_event_and_rsvp_are_scoped_to_the_audience(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Event Student', 'admission_number' => 'EVENT-1', 'class_id' => $class, 'status' => 'active']);
        $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);
        $event = $portal->save('events', $owner, ['title' => 'Annual day', 'description' => 'School celebration.', 'event_date' => '2026-10-10', 'starts_at' => '10:00', 'ends_at' => '13:00', 'location' => 'Main hall', 'audience' => 'parents', 'status' => 'published']);

        $this->actingAs($parent)->getJson('/portal/records/events')->assertOk()->assertJsonPath('rows.0.id', $event);
        $rsvp = $this->actingAs($parent)->postJson('/portal/records/event_rsvps', ['event_id' => $event, 'response' => 'attending'])->assertOk()->json('id');
        $this->actingAs($parent)->getJson('/portal/records/event_rsvps')->assertOk()->assertJsonPath('rows.0.id', $rsvp)->assertJsonPath('rows.0.response', 'attending');
        $this->assertDatabaseHas('school_event_rsvps', ['event_id' => $event, 'user_id' => $parent->id, 'response' => 'attending']);
    }
}
