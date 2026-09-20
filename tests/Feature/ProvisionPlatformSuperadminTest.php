<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionPlatformSuperadminTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operator_command_creates_a_superadmin_without_logging_password(): void
    {
        $this->artisan('platform:superadmin', ['username' => 'platform.owner', '--email' => 'platform.owner@example.test'])
            ->expectsQuestion('Superadmin password', 'platform-owner-pass-123')
            ->assertSuccessful();

        $user = User::where('username', 'platform.owner')->sole();
        $this->assertTrue($user->hasRole('superadmin'));
        $this->assertTrue(Hash::check('platform-owner-pass-123', $user->password));
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $user->id, 'action' => 'superadmin_provisioned']);
    }

    public function test_operator_command_requires_explicit_confirmation_before_promoting_an_existing_account(): void
    {
        $user = User::factory()->create(['username' => 'existing.admin', 'roles' => ['admin'], 'is_active' => true]);

        $this->artisan('platform:superadmin', ['username' => 'existing.admin'])
            ->assertFailed();
        $this->assertFalse(User::find($user->id)->hasRole('superadmin'));

        $this->artisan('platform:superadmin', ['username' => 'existing.admin', '--promote-existing' => true])
            ->expectsQuestion('Superadmin password', 'promoted-admin-pass-123')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->hasRole('superadmin'));
        $this->assertTrue(Hash::check('promoted-admin-pass-123', $user->password));
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $user->id, 'action' => 'superadmin_promoted']);
        $this->assertStringNotContainsString('promoted-admin-pass-123', (string) DB::table('platform_audit')->where('entity_id', $user->id)->value('changes'));
    }
}
