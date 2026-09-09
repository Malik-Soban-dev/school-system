<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvitationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reissuing_invitation_invalidates_previous_link_and_expiry_is_enforced(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $data = ['name' => 'Invited Teacher', 'email' => 'teacher@example.test', 'roles' => ['teacher']];
        $old = $this->actingAs($owner)->postJson('/portal/invitations', $data)->assertOk()->json('url');
        $new = $this->postJson('/portal/invitations', $data)->assertOk()->json('url');
        auth()->logout();
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
        $this->travel(49)->hours();
        $this->get($new)->assertNotFound();
    }

    public function test_suspended_inviter_cannot_activate_new_accounts(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $url = $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Teacher', 'email' => 'teacher@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        $owner->forceFill(['is_active' => false])->save();
        auth()->logout();
        $this->get($url)->assertNotFound();
    }

    public function test_suspension_revokes_database_sessions_and_blocks_access(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $teacher->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($owner)->putJson('/portal/users/'.$teacher->id, ['roles' => ['teacher'], 'is_active' => false])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->actingAs($teacher->fresh())->getJson('/portal/meta')->assertRedirect('/login');
    }
}
