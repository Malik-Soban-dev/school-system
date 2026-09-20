<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvitationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_list_and_revoke_unused_links_without_exposing_tokens(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $url = $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Teacher', 'email' => 'teacher@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        $response = $this->getJson('/portal/invitations')->assertOk()->assertJsonPath('rows.data.0.is_pending', true);
        $this->assertArrayNotHasKey('token_hash', $response->json('rows.data.0'));
        $id = $response->json('rows.data.0.id');
        $this->deleteJson('/portal/invitations/'.$id)->assertOk();
        $this->getJson('/portal/invitations')->assertJsonPath('rows.data.0.is_pending', false);
        $this->assertDatabaseHas('school_audit', ['record_id' => $id, 'module' => 'invitations', 'action' => 'revoked']);
        auth()->logout();
        $this->get($url)->assertNotFound();
        $this->post($url, ['username' => 'new.teacher', 'password' => 'Invitation-test-12345', 'password_confirmation' => 'Invitation-test-12345'])->assertNotFound();
        $this->assertDatabaseMissing('users', ['username' => 'new.teacher']);
    }

    public function test_administrator_invitations_are_protected_and_accepted_accounts_cannot_be_revoked(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Admin', 'email' => 'admin@example.test', 'roles' => ['admin']])->assertOk();
        $id = DB::table('school_invitations')->value('id');
        $this->actingAs($admin)->getJson('/portal/invitations')->assertJsonPath('rows.total', 0);
        $this->deleteJson('/portal/invitations/'.$id)->assertForbidden();
        $this->actingAs($teacher)->getJson('/portal/invitations')->assertForbidden();
        $this->deleteJson('/portal/invitations/'.$id)->assertForbidden();
        DB::table('school_invitations')->where('id', $id)->update(['accepted_at' => now()]);
        $this->actingAs($owner)->deleteJson('/portal/invitations/'.$id)->assertConflict();
        $this->getJson('/portal/invitations')->assertJsonPath('rows.total', 0);
        $this->deleteJson('/portal/invitations/99999')->assertNotFound();
    }

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

    public function test_accepted_branch_invitation_is_audited_without_password_material(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $url = $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Invited Teacher', 'email' => 'accepted@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        $token = basename(parse_url($url, PHP_URL_PATH));
        auth()->logout();

        $this->post('/invitations/'.$token, ['username' => 'accepted.teacher', 'password' => 'Invitation-test-12345', 'password_confirmation' => 'Invitation-test-12345'])->assertRedirect();
        $accepted = User::where('email', 'accepted@example.test')->firstOrFail();
        $this->assertDatabaseHas('school_audit', ['user_id' => $accepted->id, 'branch_id' => app(TenantContext::class)->branchId(), 'module' => 'invitations', 'action' => 'accepted']);
        $audit = DB::table('school_audit')->where('user_id', $accepted->id)->where('action', 'accepted')->first();
        $this->assertStringNotContainsString('Invitation-test-12345', (string) $audit->changes);
    }

    public function test_suspended_inviter_cannot_activate_new_accounts(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $url = $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Teacher', 'email' => 'teacher@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        $owner->forceFill(['is_active' => false])->save();
        auth()->logout();
        $this->get($url)->assertNotFound();
    }

    public function test_invitation_cannot_be_used_after_the_inviter_loses_branch_access(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $url = $this->actingAs($owner)->postJson('/portal/invitations', ['name' => 'Branch Teacher', 'email' => 'branch-teacher@example.test', 'roles' => ['teacher']])->assertOk()->json('url');
        $invitation = DB::table('school_invitations')->where('email', 'branch-teacher@example.test')->first(['school_id', 'branch_id']);
        DB::table('school_user_branches')->where('school_id', $invitation->school_id)->where('branch_id', $invitation->branch_id)->where('user_id', $owner->id)->update(['status' => 'suspended']);
        auth()->logout();

        $this->get($url)->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'branch-teacher@example.test']);
    }

    public function test_suspension_revokes_database_sessions_and_blocks_access(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $teacher->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($owner)->putJson('/portal/users/'.$teacher->id, ['roles' => ['teacher'], 'is_active' => false])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'is_active' => true]);
        $this->assertDatabaseHas('school_user_branches', ['user_id' => $teacher->id, 'status' => 'suspended']);
        $this->actingAs($teacher->fresh())->getJson('/portal/meta')->assertForbidden();
    }
}
