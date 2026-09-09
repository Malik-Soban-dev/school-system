<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationDelivery;
use App\Services\SchoolNotifications;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function recipient(): User
    {
        config(['app.url' => 'https://school.example.test', 'services.whatsapp' => ['enabled' => true, 'version' => 'v25.0', 'phone_number_id' => '12345', 'token' => 'test-token', 'template' => 'school_update', 'language' => 'en_US', 'app_secret' => 'test-secret', 'verify_token' => 'test-verify']]);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true, 'password' => 'Preference-test-12345']);
        app(SchoolPortal::class)->save('notices', $owner, ['title' => 'Upcoming exam', 'body' => 'Check your exam schedule.', 'audience' => 'all', 'status' => 'published']);
        app(SchoolNotifications::class)->process();
        DB::table('school_notification_preferences')->insert(['user_id' => $owner->id, 'whatsapp_phone' => '+12025550123', 'whatsapp_consented_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);

        return $owner;
    }

    public function test_whatsapp_is_sent_once_and_only_signed_statuses_confirm_delivery(): void
    {
        $this->recipient();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test-1']]])]);
        app(NotificationDelivery::class)->process();
        app(NotificationDelivery::class)->process();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['type'] === 'template' && $request['to'] === '12025550123' && count($request['template']['components'][0]['parameters']) === 3);
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'accepted', 'provider_id' => 'wamid.test-1']);
        $payload = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.test-1', 'status' => 'delivered']]]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'forged'], $payload)->assertForbidden();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret')], $payload)->assertOk();
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'delivered']);
        $payload = str_replace('delivered', 'sent', $payload);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret')], $payload)->assertOk();
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'delivered']);
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify&hub.challenge=1234')->assertOk()->assertSee('1234');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong')->assertForbidden();
    }

    public function test_rate_limit_retries_recheck_consent_and_unknown_outcomes_are_not_resent(): void
    {
        $user = $this->recipient();
        Http::fake(['graph.facebook.com/*' => Http::response([], 429)]);
        app(NotificationDelivery::class)->process();
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'pending', 'attempts' => 1]);
        DB::table('school_notification_preferences')->where('user_id', $user->id)->update(['whatsapp_consented_at' => null]);
        $this->travel(3)->minutes();
        app(NotificationDelivery::class)->process();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'skipped']);
    }

    public function test_uncertain_provider_failure_does_not_trigger_duplicate_sends(): void
    {
        $this->recipient();
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
        app(NotificationDelivery::class)->process();
        app(NotificationDelivery::class)->process();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('school_notification_deliveries', ['status' => 'unknown']);
    }

    public function test_preferences_require_current_password_and_cannot_be_written_for_other_accounts(): void
    {
        $user = $this->recipient();
        $other = User::factory()->create();
        $data = ['user_id' => $other->id, 'whatsapp_phone' => '+12025550124', 'whatsapp_enabled' => true, 'email_enabled' => true, 'current_password' => 'wrong'];
        $this->actingAs($user)->putJson('/portal/notification-preferences', $data)->assertUnprocessable();
        $this->putJson('/portal/notification-preferences', [...$data, 'current_password' => 'Preference-test-12345'])->assertOk();
        $this->getJson('/portal/notification-preferences')->assertJsonPath('whatsapp_enabled', true)->assertJsonPath('whatsapp_phone', '+12025550124');
        $this->assertDatabaseMissing('school_notification_preferences', ['user_id' => $other->id]);
    }

    public function test_email_transport_acceptance_is_not_reported_as_delivery(): void
    {
        $user = $this->recipient();
        config(['services.whatsapp.enabled' => false, 'services.school_email.enabled' => true, 'mail.default' => 'smtp']);
        DB::table('school_notification_preferences')->where('user_id', $user->id)->update(['email_consented_at' => now()->subMinute()]);
        Mail::shouldReceive('raw')->once()->withArgs(fn ($body, $callback) => str_contains($body, 'Check your exam schedule.') && is_callable($callback));
        app(NotificationDelivery::class)->process();
        app(NotificationDelivery::class)->process();
        $this->assertDatabaseHas('school_notification_deliveries', ['channel' => 'email', 'status' => 'accepted']);
        Http::assertNothingSent();
    }
}
