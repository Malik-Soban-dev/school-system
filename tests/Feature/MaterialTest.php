<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MaterialTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_published_class_material_is_visible_to_a_linked_parent(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $parent = User::factory()->create(['roles' => ['parent'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 4', 'year_id' => $year, 'capacity' => 30]);
        $subject = $portal->save('subjects', $owner, ['name' => 'Urdu', 'code' => 'URD-4']);
        $student = $portal->save('students', $owner, ['name' => 'Material Student', 'admission_number' => 'MATERIAL-1', 'class_id' => $class, 'status' => 'active']);
        $portal->save('guardian_links', $owner, ['student_id' => $student, 'user_id' => $parent->id, 'relationship' => 'Parent', 'status' => 'active']);

        $id = $this->actingAs($owner)->postJson('/portal/records/materials', ['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $owner->id, 'title' => 'Urdu reading notes', 'description' => 'Read pages 10 to 12.', 'resource_url' => 'https://example.com/urdu-notes', 'status' => 'published'])->assertOk()->json('id');
        $this->actingAs($parent)->getJson('/portal/records/materials')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonPath('rows.0.resource_url', 'https://example.com/urdu-notes');
    }
}
