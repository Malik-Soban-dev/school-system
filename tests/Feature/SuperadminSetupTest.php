<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperadminSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_setup_requires_the_temporary_token_and_creates_a_separate_superadmin_once(): void
    {
        Config::set('services.superadmin_setup.token', str_repeat('setup-token-', 4));

        $this->get('/superadmin/setup')->assertNotFound();

        $token = config('services.superadmin_setup.token');
        $this->get('/superadmin/setup?token='.$token)->assertOk()->assertSee('Create the');

        $this->post('/superadmin/setup?token='.$token, [
            'name' => 'Platform Owner',
            'username' => 'Platform.Owner',
            'password' => 'platform-owner-pass-123',
            'password_confirmation' => 'platform-owner-pass-123',
        ])->assertRedirect(route('login'));

        $user = User::where('username', 'platform.owner')->sole();
        $this->assertTrue($user->hasRole('superadmin'));
        $this->assertTrue(Hash::check('platform-owner-pass-123', $user->password));
        $this->assertDatabaseHas('platform_audit', [
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'action' => 'superadmin_provisioned',
        ]);

        $this->get('/superadmin/setup?token='.$token)->assertNotFound();
    }
}
