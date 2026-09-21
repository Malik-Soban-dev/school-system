<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
        $this->get('/register')->assertNotFound();
    }

    public function test_active_user_can_sign_in_with_normalized_username(): void
    {
        $user = User::factory()->create(['username' => 'school.owner', 'is_active' => true, 'roles' => ['owner']]);

        $this->post('/login', ['username' => ' SCHOOL.OWNER ', 'password' => 'password'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_superadmin_login_redirects_to_the_platform_dashboard(): void
    {
        $user = User::factory()->create(['username' => 'platform.owner', 'roles' => ['superadmin'], 'is_active' => true]);

        $this->post('/login', ['username' => 'platform.owner', 'password' => 'password'])->assertRedirect(route('superadmin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_superadmin_cannot_open_the_school_dashboard(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('superadmin.dashboard'));
    }

    public function test_superadmin_can_open_a_selected_school_workspace(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Workspace School', 'slug' => 'workspace-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main Branch', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->withSession(['school_id' => $school, 'branch_id' => $branch, 'school_workspace' => true])
            ->get('/dashboard')->assertOk();
    }

    #[TestWith([true, 'wrong-password'])]
    #[TestWith([false, 'password'])]
    public function test_invalid_or_inactive_credentials_are_rejected(bool $active, string $password): void
    {
        User::factory()->create(['username' => 'school.owner', 'is_active' => $active]);

        $this->post('/login', ['username' => 'school.owner', 'password' => $password])
            ->assertSessionHasErrors(['username' => 'The username or password is incorrect.']);

        $this->assertGuest();
    }

    public function test_required_login_fields_are_validated(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['username', 'password']);
        $this->assertGuest();
    }

    public function test_repeated_failed_login_is_rate_limited(): void
    {
        User::factory()->create(['username' => 'limited.user', 'is_active' => true]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'limited.user', 'password' => 'wrong']);
        }

        $this->post('/login', ['username' => 'limited.user', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in attempts. Please try again in a minute.']);

        $this->assertGuest();
    }

    public function test_suspended_account_loses_dashboard_access(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_dashboard_escapes_user_name(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>', 'username' => 'school.owner', 'is_active' => true, 'roles' => ['owner']]);

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_logout_ends_authentication(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_password_update_changes_hash_but_not_roles(): void
    {
        $user = User::factory()->create(['is_active' => true, 'roles' => ['teacher']]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password', 'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123', 'roles' => ['owner'],
        ])->assertSessionHas('status', 'Your password has been updated.');

        $this->assertTrue(Hash::check('a-new-password-123', $user->fresh()->password));
        $this->assertSame(['teacher'], $user->fresh()->roles);
    }

    #[TestWith(['incorrect', 'new-password-123', 'new-password-123', 'current_password'])]
    #[TestWith(['password', 'short', 'short', 'password'])]
    #[TestWith(['password', 'new-password-123', 'different-password', 'password'])]
    public function test_invalid_password_update_preserves_password(string $current, string $password, string $confirmation, string $error): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation,
        ])->assertSessionHasErrors($error);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_role_checks_require_active_account_and_support_multiple_roles(): void
    {
        $user = User::factory()->make(['is_active' => true, 'roles' => ['teacher', 'parent']]);

        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->hasRole('parent'));
        $this->assertFalse($user->hasRole('owner'));
        $user->is_active = false;
        $this->assertFalse($user->hasRole('teacher'));
    }
}
