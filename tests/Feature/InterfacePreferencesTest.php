<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InterfacePreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_user_can_read_and_update_interface_preferences(): void
    {
        $user = User::factory()->create(['is_active' => true, 'roles' => ['teacher']]);

        $this->actingAs($user)->getJson('/portal/interface-preferences')
            ->assertOk()->assertJson(['language' => 'en', 'theme' => 'system']);

        $this->actingAs($user)->putJson('/portal/interface-preferences', ['language' => 'ur', 'theme' => 'dark'])
            ->assertOk()->assertJson(['language' => 'ur', 'theme' => 'dark']);

        $this->assertSame('ur', $user->fresh()->preferred_language);
        $this->assertSame('dark', $user->fresh()->preferred_theme);
    }

    public function test_interface_preferences_reject_unknown_values_and_guests(): void
    {
        $user = User::factory()->create(['is_active' => true, 'roles' => ['teacher']]);

        $this->actingAs($user)->putJson('/portal/interface-preferences', ['language' => 'fr', 'theme' => 'neon'])
            ->assertUnprocessable()->assertJsonValidationErrors(['language', 'theme']);

        auth()->logout();
        $this->getJson('/portal/interface-preferences')->assertUnauthorized();
    }
}
