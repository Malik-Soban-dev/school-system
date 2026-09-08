<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSchoolOwnerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_private_command_creates_first_owner_without_email(): void
    {
        $this->artisan('school:owner', ['username' => 'test.owner'])
            ->expectsQuestion('Owner password', 'test-owner-pass-123')
            ->expectsOutput('Owner account created. Password was not logged.')
            ->assertSuccessful();

        $user = User::where('username', 'test.owner')->sole();
        $this->assertTrue($user->hasRole('owner'));
        $this->assertNull($user->email);
        $this->assertTrue(Hash::check('test-owner-pass-123', $user->password));
        $this->assertDatabaseHas('school_settings', ['key' => 'owner_provisioned', 'value' => '1']);
    }

    public function test_owner_setup_cannot_be_repeated(): void
    {
        DB::table('school_settings')->insert(['key' => 'owner_provisioned', 'value' => '1']);

        $this->artisan('school:owner', ['username' => 'second.owner'])
            ->expectsOutput('Owner setup is already complete. No account was changed.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'second.owner']);
    }

    public function test_invalid_owner_details_do_not_lock_setup(): void
    {
        $this->artisan('school:owner', ['username' => 'bad username'])
            ->expectsQuestion('Owner password', 'test-owner-pass-123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('school_settings', 0);
    }
}
