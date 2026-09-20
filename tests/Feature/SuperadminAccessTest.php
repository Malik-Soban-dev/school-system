<?php

namespace Tests\Feature;

use App\Jobs\BuildPlatformSchoolExport;
use App\Jobs\BuildPlatformUserExport;
use App\Models\User;
use App\Support\PlatformErrorRecorder;
use App\Support\PlatformSchedulerRuns;
use App\Support\TenantContext;
use App\Support\Totp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SuperadminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_enroll_mfa_and_must_verify_it_on_next_login(): void
    {
        $superadmin = User::factory()->create(['username' => 'mfa.superadmin', 'roles' => ['superadmin'], 'is_active' => true]);
        $this->actingAs($superadmin)->post('/account/mfa/setup', ['current_password' => 'password'])->assertRedirect(route('account'));
        $secret = Crypt::decryptString($this->app['request']->session()->get('mfa_pending_secret'));
        $reflection = new \ReflectionClass(Totp::class);
        $codeMethod = $reflection->getMethod('code');
        $codeMethod->setAccessible(true);
        $code = $codeMethod->invoke(null, $secret, intdiv(time(), 30));

        $this->post('/account/mfa/confirm', ['current_password' => 'password', 'code' => $code])->assertRedirect(route('account'));
        $this->assertDatabaseHas('users', ['id' => $superadmin->id]);
        $this->assertNotNull(User::find($superadmin->id)->mfa_enabled_at);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $superadmin->id, 'action' => 'mfa_enabled']);
        $recoveryCode = $this->app['session']->get('mfa_recovery_codes')[0];

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'mfa.superadmin', 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->post('/mfa/challenge', ['code' => $recoveryCode])->assertRedirect(route('dashboard'));
        $this->get('/superadmin')->assertOk();
        $this->assertCount(7, json_decode((string) User::find($superadmin->id)->mfa_recovery_codes, true));
    }

    public function test_superadmin_can_open_platform_dashboard(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->get('/superadmin')->assertOk()->assertSee('Superadmin dashboard')->assertSee('Account security')->assertSee('Search school or slug');
    }

    public function test_superadmin_can_switch_into_any_branch_workspace_and_use_full_portal_authority(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Workspace School', 'slug' => 'workspace-school', 'status' => 'suspended', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Workspace Campus', 'code' => 'workspace', 'status' => 'suspended', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/portal/contexts')->assertOk()->assertJsonFragment(['school_id' => $school, 'branch_id' => $branch, 'school_name' => 'Workspace School', 'branch_name' => 'Workspace Campus', 'roles' => ['superadmin']]);
        $this->actingAs($superadmin)->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $branch])->assertOk()->assertJsonPath('current.branch_id', $branch);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'workspace', 'entity_id' => $branch, 'action' => 'superadmin_context_switched']);

        $this->actingAs($superadmin)->getJson('/portal/meta')->assertOk()->assertJsonPath('current_context.school_id', $school)->assertJsonPath('current_context.branch_id', $branch)->assertJsonPath('canManage', true);
        $this->actingAs($superadmin)->postJson('/portal/records/notices', ['title' => 'Platform notice', 'body' => 'Managed centrally', 'audience' => 'all', 'status' => 'published'])->assertOk();
        $this->assertDatabaseHas('school_notices', ['school_id' => $school, 'branch_id' => $branch, 'title' => 'Platform notice']);
    }

    public function test_single_branch_plan_blocks_client_context_switch_until_superadmin_enables_branch_access(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Single Branch School', 'slug' => 'single-branch-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $default = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $second = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Second', 'code' => 'second', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert([['school_id' => $school, 'branch_id' => $default, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['school_id' => $school, 'branch_id' => $second, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $restricted = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $restricted->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $second, 'user_id' => $restricted->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($client)->getJson('/portal/contexts')->assertOk()->assertJsonCount(1, 'contexts')->assertJsonPath('contexts.0.branch_id', $default);
        $this->actingAs($restricted)->getJson('/portal/meta')->assertForbidden();
        $this->actingAs($client)->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $second])->assertForbidden();
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/features', ['feature' => 'branches', 'enabled' => true])->assertOk();
        $this->actingAs($client)->getJson('/portal/contexts')->assertOk()->assertJsonCount(2, 'contexts');
        $this->actingAs($client)->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $second])->assertOk()->assertJsonPath('current.branch_id', $second);
    }

    public function test_superadmin_can_manage_an_owner_access_grant_inside_the_selected_workspace(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Workspace Authority School', 'slug' => 'workspace-authority-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $owner->id, 'roles' => json_encode(['owner']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $branch])->assertOk();
        $this->actingAs($superadmin)->putJson('/portal/users/'.$owner->id, ['roles' => ['admin'], 'is_active' => true])->assertOk();
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch, 'user_id' => $owner->id, 'roles' => json_encode(['admin'])]);
    }

    public function test_superadmin_can_grant_one_client_access_to_multiple_school_branches_atomically(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Bulk Access School', 'slug' => 'bulk-access-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branches = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'North', 'code' => 'north', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $south = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'South', 'code' => 'south', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/members/'.$client->id.'/branch-access/bulk', ['branch_ids' => [$branches, $south], 'roles' => ['admin'], 'status' => 'active'])->assertOk();
        $this->assertDatabaseCount('school_user_branches', 2);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branches, 'user_id' => $client->id, 'status' => 'active']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $south, 'user_id' => $client->id, 'status' => 'active']);
        $this->assertDatabaseCount('school_audit', 3);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'bulk_branch_access_updated']);
    }

    public function test_superadmin_can_review_a_paginated_cross_school_branch_registry(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Registry School', 'slug' => 'branch-registry-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_branches')->insert([
            ['school_id' => $school, 'name' => 'North Branch', 'code' => 'north', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'name' => 'South Branch', 'code' => 'south', 'status' => 'suspended', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/branches?school_id='.$school.'&status=active&search=North&per_page=1')->assertOk()->assertJsonPath('branches.total', 1)->assertJsonPath('branches.data.0.school_name', 'Branch Registry School')->assertJsonPath('branches.data.0.name', 'North Branch')->assertJsonPath('branches.data.0.students', 0);
    }

    public function test_superadmin_school_detail_does_not_truncate_large_member_rosters(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Large Roster School', 'slug' => 'large-roster-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $users = User::factory()->count(101)->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert($users->map(fn (User $user): array => ['school_id' => $school, 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()])->all());
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->assertJsonCount(101, 'members');
    }

    public function test_superadmin_user_registry_preserves_pagination_and_branch_access_metadata(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'User Registry School', 'slug' => 'user-registry-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'User Branch', 'code' => 'users', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Paginated Client A', 'email' => 'paginated-client-a@example.test', 'roles' => ['admin'], 'is_active' => true]);
        User::factory()->create(['name' => 'Paginated Client B', 'email' => 'paginated-client-b@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/users?search=paginated-client&per_page=1')->assertOk()->assertJsonPath('users.total', 2)->assertJsonPath('users.last_page', 2)->assertJsonPath('users.data.0.access.0.branch_name', 'User Branch')->assertJsonPath('users.data.0.access.0.roles.0', 'admin');
        $this->actingAs($superadmin)->getJson('/superadmin/users?search=paginated-client&per_page=1&page=2')->assertOk()->assertJsonPath('users.current_page', 2)->assertJsonPath('users.data.0.email', 'paginated-client-b@example.test');
        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&branch_id='.$branch.'&status=active')->assertOk()->assertJsonPath('users.total', 1)->assertJsonPath('users.data.0.email', 'paginated-client-a@example.test');
        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&branch_id='.$branch.'&role=admin')->assertOk()->assertJsonPath('users.total', 1)->assertJsonPath('users.data.0.email', 'paginated-client-a@example.test');
    }

    public function test_branch_role_filter_uses_the_selected_branch_grant(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Role Filter School', 'slug' => 'role-filter-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Role Branch', 'code' => 'role', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['email' => 'role-filter-client@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['teacher']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&branch_id='.$branch.'&role=admin')->assertOk()->assertJsonPath('users.total', 0);
        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&branch_id='.$branch.'&role=teacher')->assertOk()->assertJsonPath('users.total', 1)->assertJsonPath('users.data.0.email', 'role-filter-client@example.test');
        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&role=admin')->assertOk()->assertJsonPath('users.total', 0);
        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&role=teacher')->assertOk()->assertJsonPath('users.total', 1)->assertJsonPath('users.data.0.email', 'role-filter-client@example.test');
    }

    public function test_superadmin_branch_registry_includes_suspended_grants_for_restore_operations(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Suspended Registry School', 'slug' => 'suspended-registry-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Suspended Branch', 'code' => 'suspended', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['email' => 'suspended-registry-client@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'suspended', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/users?school_id='.$school.'&branch_id='.$branch.'&role=admin')->assertOk()->assertJsonPath('users.total', 1)->assertJsonPath('users.data.0.email', 'suspended-registry-client@example.test')->assertJsonPath('users.data.0.access.0.status', 'suspended');
        $this->actingAs($superadmin)->getJson('/superadmin/users?branch_id='.$branch)->assertOk()->assertJsonPath('users.total', 1);
    }

    public function test_superadmin_can_review_platform_health_without_backup_contents(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $school = DB::table('schools')->insertGetId(['name' => 'Health notification school', 'slug' => 'health-notification-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Health branch', 'code' => 'health', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $notification = DB::table('school_notifications')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $user->id, 'event_key' => 'health-test', 'module' => 'exams', 'record_id' => 1, 'title' => 'Health test', 'body' => 'Health body', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notification_deliveries')->insert(['school_id' => $school, 'branch_id' => $branch, 'notification_id' => $notification, 'channel' => 'email', 'status' => 'failed', 'attempts' => 2, 'error_code' => 'provider_error', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $schedulerRuns = app(PlatformSchedulerRuns::class);
        $schedulerRuns->start('platform:mark-overdue-invoices');
        $schedulerRuns->finish('platform:mark-overdue-invoices', 0);
        app(PlatformErrorRecorder::class)->record(new \RuntimeException('private diagnostic message must not be exposed'));

        $this->actingAs($user)->getJson('/superadmin/health')->assertOk()->assertJsonPath('database', 'ok')->assertJsonPath('status', 'attention')->assertJsonPath('storage.available', true)->assertJsonPath('scheduled_runs.0.command', 'platform:mark-overdue-invoices')->assertJsonPath('scheduled_runs.0.status', 'success')->assertJsonPath('application_errors.last_24h', 1)->assertJsonPath('notification_deliveries.last_24h', 1)->assertJsonPath('notification_deliveries.failed_24h', 1)->assertJsonPath('notification_deliveries.by_status.0.status', 'failed')->assertJsonPath('notification_deliveries.recent_failures.0.school_name', 'Health notification school')->assertJsonStructure(['status', 'queue' => ['pending', 'failed'], 'schools' => ['active', 'suspended'], 'storage' => ['available', 'bytes', 'files'], 'scheduled_runs', 'application_errors' => ['last_24h', 'recent'], 'notification_deliveries' => ['available', 'last_24h', 'failed_24h', 'by_status', 'recent_failures'], 'backups'])->assertJsonMissing(['private diagnostic message must not be exposed']);
    }

    public function test_superadmin_can_create_an_encrypted_backup_without_receiving_contents(): void
    {
        $directory = storage_path('app/private/backups');
        File::deleteDirectory($directory);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        try {
            $response = $this->actingAs($superadmin)->postJson('/superadmin/operations/backups')->assertCreated()->assertJsonStructure(['backup' => ['name', 'bytes', 'modified_at'], 'message']);
            $backup = $response->json('backup');
            $this->assertStringEndsWith('.enc', $backup['name']);
            $this->assertGreaterThan(0, $backup['bytes']);
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$backup['name']);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_created']);
            $response->assertJsonMissing(['contents' => '']);
            $this->actingAs($superadmin)->postJson('/superadmin/operations/backups/verify', ['name' => $backup['name']])->assertOk()->assertJsonPath('name', $backup['name']);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_verified']);
            $this->actingAs($superadmin)->get('/superadmin/operations/backups/'.$backup['name'].'/download')->assertDownload($backup['name']);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_downloaded']);
            $this->actingAs($superadmin)->postJson('/superadmin/operations/backups/verify', ['name' => '../'.$backup['name']])->assertUnprocessable();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_superadmin_can_review_and_forget_failed_job_metadata_without_payload_exposure(): void
    {
        $job = DB::table('failed_jobs')->insertGetId(['uuid' => 'failed-job-test-uuid', 'connection' => 'database', 'queue' => 'default', 'payload' => 'secret-payload', 'exception' => 'secret-exception', 'failed_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/operations/failed-jobs')->assertOk()->assertJsonPath('failed_jobs.data.0.id', $job)->assertJsonPath('failed_jobs.data.0.queue', 'default')->assertJsonMissing(['payload' => 'secret-payload'])->assertJsonMissing(['exception' => 'secret-exception']);
        $this->actingAs($superadmin)->deleteJson('/superadmin/operations/failed-jobs/'.$job)->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['id' => $job]);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'failed_job', 'entity_id' => $job, 'action' => 'failed_job_forgotten']);
    }

    public function test_superadmin_can_requeue_a_failed_job_with_auditing_without_payload_exposure(): void
    {
        $job = DB::table('failed_jobs')->insertGetId(['uuid' => 'retry-job-test-uuid', 'connection' => 'database', 'queue' => 'default', 'payload' => 'retry-payload', 'exception' => 'retry-exception', 'failed_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->postJson('/superadmin/operations/failed-jobs/'.$job.'/retry')->assertOk()->assertJsonPath('message', 'Failed job requeued for processing.');
        $this->assertDatabaseMissing('failed_jobs', ['id' => $job]);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'failed_job', 'entity_id' => $job, 'action' => 'failed_job_retried']);
    }

    public function test_superadmin_can_queue_and_download_a_complete_user_registry_export_without_secrets(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Export School', 'slug' => 'export-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Export Branch', 'code' => 'export', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Export Client', 'username' => 'export-client', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/operations/exports/users')->assertAccepted();
        $exportId = $response->json('export.id');
        $this->assertDatabaseHas('platform_exports', ['id' => $exportId, 'type' => 'users', 'status' => 'completed', 'row_count' => 2]);
        $path = storage_path('app/private/exports/users-'.$exportId.'.csv');
        try {
            $this->assertFileExists($path);
            $this->actingAs($superadmin)->get('/superadmin/operations/exports/'.$exportId.'/download')->assertDownload('school-system-users-'.$exportId.'.csv');
            $csv = file_get_contents($path);
            $this->assertStringContainsString('Export Client', $csv);
            $this->assertStringNotContainsString('password', strtolower($csv));
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $exportId, 'action' => 'user_export_completed']);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $exportId, 'action' => 'user_export_downloaded']);
        } finally {
            File::delete($path);
        }
    }

    public function test_superadmin_can_queue_and_download_a_school_data_export_without_credentials(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Tenant Export School', 'slug' => 'tenant-export-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Tenant Branch', 'code' => 'tenant', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Tenant Export Client', 'username' => 'tenant-export-client', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/operations/exports')->assertAccepted();
        $exportId = $response->json('export.id');
        $this->assertDatabaseHas('platform_exports', ['id' => $exportId, 'school_id' => $school, 'type' => 'school', 'status' => 'completed']);
        $path = storage_path('app/private/exports/school-'.$school.'-'.$exportId.'.ndjson');
        try {
            $this->assertFileExists($path);
            $this->assertStringContainsString('Tenant Export School', file_get_contents($path));
            $this->assertStringContainsString('Tenant Export Client', file_get_contents($path));
            $this->assertStringNotContainsString('password', strtolower(file_get_contents($path)));
            $this->assertStringNotContainsString('token_hash', file_get_contents($path));
            $this->actingAs($superadmin)->get('/superadmin/operations/exports/'.$exportId.'/download')->assertDownload('school-system-school-'.$school.'-'.$exportId.'.ndjson');
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $exportId, 'action' => 'school_export_completed']);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $exportId, 'action' => 'school_export_downloaded']);
        } finally {
            File::delete($path);
        }
    }

    public function test_failed_exports_remove_partial_files_and_create_safe_failure_audits(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $school = DB::table('schools')->insertGetId(['name' => 'Failed Export School', 'slug' => 'failed-export-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $userExportId = DB::table('platform_exports')->insertGetId(['requested_by' => $superadmin->id, 'type' => 'users', 'status' => 'processing', 'file_path' => 'exports/users-999998.csv', 'created_at' => now(), 'updated_at' => now()]);
        $schoolExportId = DB::table('platform_exports')->insertGetId(['requested_by' => $superadmin->id, 'school_id' => $school, 'type' => 'school', 'status' => 'processing', 'file_path' => 'exports/school-'.$school.'-999997.ndjson', 'created_at' => now(), 'updated_at' => now()]);
        $userPath = storage_path('app/private/exports/users-'.$userExportId.'.csv');
        $schoolPath = storage_path('app/private/exports/school-'.$school.'-'.$schoolExportId.'.ndjson');
        File::ensureDirectoryExists(dirname($userPath));
        File::put($userPath, 'partial');
        File::put($schoolPath, 'partial');

        (new BuildPlatformUserExport($userExportId))->failed(new \RuntimeException('user worker failed'));
        (new BuildPlatformSchoolExport($schoolExportId, $school))->failed(new \RuntimeException('school worker failed'));

        $this->assertDatabaseHas('platform_exports', ['id' => $userExportId, 'status' => 'failed', 'file_path' => null]);
        $this->assertDatabaseHas('platform_exports', ['id' => $schoolExportId, 'status' => 'failed', 'file_path' => null]);
        $this->assertFileDoesNotExist($userPath);
        $this->assertFileDoesNotExist($schoolPath);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $userExportId, 'action' => 'user_export_failed']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $schoolExportId, 'action' => 'school_export_failed']);
    }

    public function test_expired_platform_exports_are_pruned_with_audit_trail(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $relativePath = 'exports/users-999999.csv';
        $path = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "expired export\n");
        $exportId = DB::table('platform_exports')->insertGetId([
            'requested_by' => $superadmin->id,
            'type' => 'users',
            'status' => 'completed',
            'file_path' => $relativePath,
            'row_count' => 3,
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        try {
            $this->artisan('platform:prune-exports')->assertExitCode(0);
            $this->assertDatabaseMissing('platform_exports', ['id' => $exportId]);
            $this->assertFileDoesNotExist($path);
            $this->assertDatabaseHas('platform_audit', ['entity_type' => 'export', 'entity_id' => $exportId, 'action' => 'export_pruned']);
        } finally {
            File::delete($path);
        }
    }

    public function test_superadmin_can_search_paginated_cross_school_audit_without_leaking_other_school_events_when_filtered(): void
    {
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Audit School', 'slug' => 'audit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $schoolTwo, 'name' => 'Audit Campus', 'code' => 'audit-campus', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_audit')->insert([
            ['school_id' => 1, 'branch_id' => null, 'user_id' => null, 'module' => 'students', 'record_id' => 1, 'action' => 'first_school_event', 'changes' => '{}', 'created_at' => now()->subMinute()],
            ['school_id' => $schoolTwo, 'branch_id' => $branch, 'user_id' => null, 'module' => 'billing', 'record_id' => 1, 'action' => 'second_school_event', 'changes' => '{}', 'created_at' => now()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/audit?school_id='.$schoolTwo.'&branch_id='.$branch.'&per_page=1')->assertOk()->assertJsonPath('audit.total', 1)->assertJsonPath('audit.data.0.action', 'second_school_event')->assertJsonPath('audit.data.0.branch_id', $branch);
        $this->actingAs($superadmin)->getJson('/superadmin/audit?search=first_school_event')->assertOk()->assertJsonPath('audit.data.0.school_id', 1);
    }

    public function test_superadmin_data_explorer_audit_filter_is_branch_scoped(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Explorer Audit School', 'slug' => 'explorer-audit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branchOne = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'North Campus', 'code' => 'north', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branchTwo = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'South Campus', 'code' => 'south', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_audit')->insert([
            ['school_id' => $school, 'branch_id' => $branchOne, 'user_id' => null, 'module' => 'students', 'record_id' => 1, 'action' => 'north_event', 'changes' => '{}', 'created_at' => now()->subMinute()],
            ['school_id' => $school, 'branch_id' => $branchTwo, 'user_id' => null, 'module' => 'students', 'record_id' => 2, 'action' => 'south_event', 'changes' => '{}', 'created_at' => now()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/audit?branch_id='.$branchOne)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.action', 'north_event')->assertJsonPath('records.data.0.branch_id', $branchOne)->assertJsonMissing(['action' => 'south_event']);
    }

    public function test_platform_audit_events_remain_visible_when_filtered_to_their_school(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Filtered Audit School', 'slug' => 'filtered-audit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_audit')->insert(['user_id' => null, 'entity_type' => 'subscription', 'entity_id' => $school, 'action' => 'subscription_updated', 'changes' => json_encode(['school_id' => $school, 'status' => 'active']), 'created_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/audit?school_id='.$school)->assertOk()->assertJsonPath('audit.total', 1)->assertJsonPath('audit.data.0.action', 'subscription_updated')->assertJsonPath('audit.data.0.source', 'platform');
    }

    public function test_superadmin_data_explorer_covers_grading_bands_and_exam_subjects(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Assessment Explorer School', 'slug' => 'assessment-explorer-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Assessment Campus', 'code' => 'assessment', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $subject = DB::table('school_subjects')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Mathematics', 'code' => 'MATH-EXPLORER', 'created_at' => now(), 'updated_at' => now()]);
        $exam = DB::table('school_exams')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Term One', 'class_id' => $class, 'date' => '2026-05-01', 'status' => 'draft', 'schedule_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_grade_bands')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'A', 'minimum' => 80, 'gpa' => 4, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_exam_subjects')->insert(['school_id' => $school, 'branch_id' => $branch, 'exam_id' => $exam, 'subject_id' => $subject, 'maximum' => 100, 'weight' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/grade_bands?branch_id='.$branch)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.name', 'A');
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/exam_subjects?branch_id='.$branch)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.subject', 'Mathematics');
        DB::table('school_settings')->insert(['school_id' => $school, 'key' => 'logo_data', 'value' => 'private-image-data']);
        DB::table('school_settings')->insert(['school_id' => $school, 'key' => 'provider_api_token', 'value' => 'private-provider-token']);
        $notification = DB::table('school_notifications')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $superadmin->id, 'event_key' => 'explorer-test', 'module' => 'exams', 'record_id' => $exam, 'title' => 'Exam reminder', 'body' => 'Reminder body', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notification_deliveries')->insert(['school_id' => $school, 'branch_id' => $branch, 'notification_id' => $notification, 'channel' => 'email', 'status' => 'failed', 'attempts' => 2, 'available_at' => now(), 'error_code' => 'provider_error', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/settings')->assertOk()->assertJsonPath('records.data.0.value', '[redacted asset]')->assertJsonMissing(['value' => 'private-image-data']);
        $this->assertDatabaseHas('school_settings', ['school_id' => $school, 'key' => 'provider_api_token', 'value' => 'private-provider-token']);
        $settingsResponse = $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/settings')->assertOk();
        $settingsResponse->assertJsonFragment(['key' => 'provider_api_token', 'value' => '[redacted setting]'])->assertJsonMissing(['value' => 'private-provider-token']);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/notification_deliveries?branch_id='.$branch)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.status', 'failed')->assertJsonPath('records.data.0.recipient', $superadmin->name);
        DB::table('school_notification_preferences')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $superadmin->id, 'whatsapp_phone' => '+15555550123', 'whatsapp_consented_at' => now(), 'email_consented_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notification_events')->insert(['school_id' => $school, 'branch_id' => $branch, 'module' => 'exams', 'record_id' => $exam, 'event_key' => 'exam:'.$exam.':reminder', 'created_at' => now()]);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/notification_preferences?branch_id='.$branch)->assertOk()->assertJsonPath('records.data.0.whatsapp_configured', 1)->assertJsonMissing(['whatsapp_phone' => '+15555550123']);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/notification_events?branch_id='.$branch)->assertOk()->assertJsonPath('records.data.0.event_key', 'exam:'.$exam.':reminder');
    }

    public function test_superadmin_can_assign_and_audit_a_school_subscription(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Billing School', 'slug' => 'billing-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/subscription', ['plan_id' => $plan, 'status' => 'active', 'renews_at' => '2027-01-01'])->assertOk();
        DB::table('platform_billing_invoices')->insert([
            ['school_id' => $school, 'invoice_number' => 'PLAT-BILLING-PAID', 'amount_cents' => 4900, 'currency' => 'USD', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_on' => '2026-09-15', 'status' => 'paid', 'paid_at' => now(), 'payment_reference' => 'receipt-1', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'invoice_number' => 'PLAT-BILLING-OPEN', 'amount_cents' => 14900, 'currency' => 'USD', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-15', 'status' => 'issued', 'paid_at' => null, 'payment_reference' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertDatabaseHas('school_subscriptions', ['school_id' => $school, 'plan_id' => $plan, 'status' => 'active', 'renews_at' => '2027-01-01']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'module' => 'billing', 'action' => 'subscription_updated']);
        $this->assertDatabaseHas('platform_audit', ['school_id' => $school, 'entity_type' => 'subscription', 'entity_id' => $school, 'action' => 'subscription_updated']);
        $platformAudit = collect($this->actingAs($superadmin)->getJson('/superadmin/audit?school_id='.$school)->assertOk()->json('audit.data'))->first(fn (array $row): bool => $row['source'] === 'platform' && $row['action'] === 'subscription_updated');
        $this->assertSame('Billing School', $platformAudit['school_name']);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/subscription', ['plan_id' => $plan, 'status' => 'canceled'])->assertOk();
        $this->assertNotNull(DB::table('school_subscriptions')->where('school_id', $school)->value('canceled_at'));
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/subscription', ['plan_id' => $plan, 'status' => 'active', 'renews_at' => '2027-01-01'])->assertOk();
        $this->assertNull(DB::table('school_subscriptions')->where('school_id', $school)->value('canceled_at'));
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->assertJsonPath('billing.paid_cents', 4900)->assertJsonPath('billing.outstanding_cents', 14900)->assertJsonPath('billing.invoices.0.invoice_number', 'PLAT-BILLING-OPEN');
    }

    public function test_superadmin_dashboard_recent_audit_keeps_school_and_branch_attribution(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Dashboard School', 'slug' => 'dashboard-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'North Branch', 'code' => 'NORTH', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        DB::table('platform_audit')->insert(['user_id' => $superadmin->id, 'school_id' => $school, 'branch_id' => $branch, 'action' => 'branch_status_updated', 'entity_type' => 'branch', 'entity_id' => $branch, 'changes' => json_encode(['status' => 'active']), 'created_at' => now()]);

        $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->assertJsonFragment(['action' => 'branch_status_updated', 'school_name' => 'Dashboard School', 'branch_name' => 'North Branch']);
    }

    public function test_superadmin_school_detail_activity_keeps_branch_attribution(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Detail Audit School', 'slug' => 'detail-audit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'East Branch', 'code' => 'east', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branch, 'record_id' => $branch, 'module' => 'platform', 'action' => 'detail_branch_event', 'changes' => json_encode(['branch_id' => $branch]), 'created_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->assertJsonFragment(['action' => 'detail_branch_event', 'branch_id' => $branch, 'branch_name' => 'East Branch']);
    }

    public function test_school_billing_totals_include_history_beyond_the_display_window(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Long Billing School', 'slug' => 'long-billing-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_billing_invoices')->insert(collect(range(1, 51))->map(fn (int $number): array => ['school_id' => $school, 'invoice_number' => 'PLAT-LONG-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'amount_cents' => 100, 'currency' => 'USD', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'due_on' => '2026-02-01', 'status' => 'paid', 'paid_at' => now(), 'payment_reference' => 'history-'.$number, 'created_at' => now(), 'updated_at' => now()])->all());
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->assertJsonPath('billing.paid_cents', 5100)->assertJsonCount(50, 'billing.invoices');
    }

    public function test_superadmin_can_override_a_school_feature_and_clear_it_back_to_the_plan(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Feature Override School', 'slug' => 'feature-override-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/features', ['feature' => 'payroll', 'enabled' => true])->assertOk()->assertJsonPath('enabled', true);
        $this->assertDatabaseHas('school_feature_overrides', ['school_id' => $school, 'feature' => 'payroll', 'enabled' => 1]);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'school_feature', 'entity_id' => $school, 'action' => 'school_feature_override_updated']);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/features', ['feature' => 'payroll', 'enabled' => null])->assertOk()->assertJsonPath('enabled', null);
        $this->assertDatabaseMissing('school_feature_overrides', ['school_id' => $school, 'feature' => 'payroll']);
    }

    public function test_platform_summary_reports_billing_metrics(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Metrics School', 'slug' => 'metrics-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->first(['id', 'monthly_price_cents']);
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->assertJsonPath('billing.mrr_cents', (int) $plan->monthly_price_cents)->assertJsonPath('billing.subscriptions.active', 1)->assertJsonFragment(['code' => 'starter', 'name' => 'Starter', 'total' => 1]);
    }

    public function test_platform_registry_reports_school_usage_against_plan_limits(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Usage School', 'slug' => 'usage-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Usage Branch', 'code' => 'usage', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->first(['id', 'name', 'max_branches', 'max_students']);
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Usage Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Usage Class', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Usage Student', 'admission_number' => 'USAGE-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $payload = $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->json();
        $schools = $payload['schools'];
        $record = collect($schools)->firstWhere('id', $school);
        $this->assertNotNull($record);
        $this->assertSame($plan->name, $record['plan_name']);
        $this->assertSame('active', $record['subscription_status']);
        $this->assertSame(1, (int) $record['branches']);
        $this->assertSame(1, (int) $record['students']);
        $this->assertSame(1, (int) $payload['summary']['classes']);
        $this->assertSame((int) $plan->max_branches, (int) $record['max_branches']);
        $this->assertSame((int) $plan->max_students, (int) $record['max_students']);
        $this->assertCount(1, collect($payload['entitlement_alerts'])->where('school_id', $school));
        $this->assertSame('branch_limit', collect($payload['entitlement_alerts'])->where('school_id', $school)->first()['type']);
    }

    public function test_superadmin_can_issue_and_reconcile_a_platform_invoice(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Invoice School', 'slug' => 'invoice-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $invoice = $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/billing/invoices', ['amount_cents' => 14900, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_on' => '2026-10-01', 'notes' => 'Growth subscription'])->assertCreated()->json('invoice');
        $this->assertDatabaseHas('platform_billing_invoices', ['id' => $invoice['id'], 'school_id' => $school, 'amount_cents' => 14900, 'status' => 'issued']);
        $this->actingAs($superadmin)->putJson('/superadmin/billing/invoices/'.$invoice['id'].'/status', ['status' => 'paid'])->assertUnprocessable();
        $this->actingAs($superadmin)->putJson('/superadmin/billing/invoices/'.$invoice['id'].'/status', ['status' => 'paid', 'payment_reference' => 'manual-rcpt-1'])->assertOk();
        $this->assertDatabaseHas('platform_billing_invoices', ['id' => $invoice['id'], 'status' => 'paid', 'payment_reference' => 'manual-rcpt-1']);
        $this->actingAs($superadmin)->getJson('/superadmin/billing/invoices?school_id='.$school)->assertOk()->assertJsonPath('invoices.total', 1)->assertJsonPath('invoices.data.0.invoice_number', $invoice['invoice_number']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'platform_invoice', 'entity_id' => $invoice['id'], 'action' => 'invoice_status_updated']);
    }

    public function test_superadmin_billing_registry_supports_pagination_and_status_filters(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Invoice Registry School', 'slug' => 'invoice-registry-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        foreach (['2026-09-01', '2026-10-01'] as $periodStart) {
            $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/billing/invoices', ['amount_cents' => 9900, 'period_start' => $periodStart, 'period_end' => $periodStart === '2026-09-01' ? '2026-09-30' : '2026-10-31', 'due_on' => $periodStart])->assertCreated();
        }

        $this->actingAs($superadmin)->getJson('/superadmin/billing/invoices?per_page=1&page=2')->assertOk()->assertJsonPath('invoices.total', 2)->assertJsonPath('invoices.last_page', 2);
        $this->actingAs($superadmin)->getJson('/superadmin/billing/invoices?status=issued')->assertOk()->assertJsonPath('invoices.total', 2);
    }

    public function test_platform_invoice_generation_is_repeat_safe_for_due_subscriptions(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Renewal School', 'slug' => 'renewal-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->first(['id', 'monthly_price_cents']);
        $subscription = DB::table('school_subscriptions')->insertGetId(['school_id' => $school, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => '2026-08-01 00:00:00', 'renews_at' => '2026-09-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('platform:generate-invoices', ['--until' => '2026-09-19']);
        Artisan::call('platform:generate-invoices', ['--until' => '2026-09-19']);

        $this->assertDatabaseCount('platform_billing_invoices', 1);
        $this->assertDatabaseHas('school_subscriptions', ['id' => $subscription, 'renews_at' => '2026-10-01 00:00:00']);
        $invoice = DB::table('platform_billing_invoices')->first();
        $this->assertSame($school, (int) $invoice->school_id);
        $this->assertSame($subscription, (int) $invoice->subscription_id);
        $this->assertSame('renewal:'.$subscription.':2026-09-01', $invoice->billing_key);
        $this->assertSame((int) $plan->monthly_price_cents, (int) $invoice->amount_cents);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'platform_invoice', 'entity_id' => $invoice->id, 'action' => 'invoice_generated']);

        Artisan::call('platform:generate-invoices', ['--until' => '2026-10-02']);

        $this->assertDatabaseCount('platform_billing_invoices', 2);
        $this->assertDatabaseHas('platform_billing_invoices', ['subscription_id' => $subscription, 'billing_key' => 'renewal:'.$subscription.':2026-10-01']);
        $this->assertDatabaseHas('school_subscriptions', ['id' => $subscription, 'renews_at' => '2026-11-01 00:00:00']);
    }

    public function test_platform_invoice_overdue_command_is_repeat_safe_and_audited(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Overdue School', 'slug' => 'overdue-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $invoice = DB::table('platform_billing_invoices')->insertGetId(['school_id' => $school, 'invoice_number' => 'PLAT-OVERDUE-1', 'amount_cents' => 4900, 'currency' => 'USD', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_on' => '2026-09-01', 'status' => 'issued', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('platform:mark-overdue-invoices', ['--until' => '2026-09-19']);
        Artisan::call('platform:mark-overdue-invoices', ['--until' => '2026-09-19']);

        $this->assertDatabaseHas('platform_billing_invoices', ['id' => $invoice, 'status' => 'overdue']);
        $this->assertDatabaseCount('platform_audit', 1);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'platform_invoice', 'entity_id' => $invoice, 'action' => 'invoice_marked_overdue']);
    }

    public function test_superadmin_can_update_a_plan_with_platform_auditing(): void
    {
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/plans/'.$plan, ['name' => 'Starter Plus', 'monthly_price_cents' => 5900, 'max_branches' => 2, 'max_students' => 500, 'features' => ['attendance', 'grades', 'invoices'], 'status' => 'active'])->assertOk();

        $this->assertDatabaseHas('platform_plans', ['id' => $plan, 'name' => 'Starter Plus', 'monthly_price_cents' => 5900, 'max_students' => 500]);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'plan', 'entity_id' => $plan, 'action' => 'plan_updated']);
        $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->assertJsonFragment(['action' => 'plan_updated', 'school_name' => 'Platform']);
    }

    public function test_superadmin_can_create_a_new_platform_plan_with_audit(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $plan = $this->actingAs($superadmin)->postJson('/superadmin/plans', ['code' => 'pro-plus', 'name' => 'Pro Plus', 'monthly_price_cents' => 24900, 'max_branches' => 10, 'max_students' => 5000, 'features' => ['attendance', 'grades', 'payroll', 'branches']])->assertCreated()->json('plan');

        $this->assertSame('pro-plus', $plan['code']);
        $this->assertDatabaseHas('platform_plans', ['id' => $plan['id'], 'code' => 'pro-plus', 'monthly_price_cents' => 24900, 'status' => 'active']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'plan', 'entity_id' => $plan['id'], 'action' => 'plan_created']);
    }

    public function test_superadmin_cannot_archive_an_assigned_plan_or_the_last_active_plan(): void
    {
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        $school = DB::table('schools')->insertGetId(['name' => 'Plan Guard School', 'slug' => 'plan-guard-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $payload = ['name' => 'Starter', 'monthly_price_cents' => 4900, 'max_branches' => 1, 'max_students' => 250, 'features' => ['attendance'], 'status' => 'archived'];

        $this->actingAs($superadmin)->putJson('/superadmin/plans/'.$plan, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('platform_plans', ['id' => $plan, 'status' => 'active']);
        DB::table('school_subscriptions')->where('school_id', $school)->update(['status' => 'canceled']);
        DB::table('platform_plans')->where('status', 'active')->where('id', '!=', $plan)->update(['status' => 'archived']);
        $this->actingAs($superadmin)->putJson('/superadmin/plans/'.$plan, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('platform_plans', ['id' => $plan, 'status' => 'active']);
    }

    public function test_superadmin_can_review_and_suspend_a_school_with_audited_status_change(): void
    {
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Managed School', 'slug' => 'managed-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $schoolTwo, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'managed-school-session', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()]);
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->getJson('/superadmin/data')->assertOk()->assertJsonPath('summary.schools', 2)->assertJsonFragment(['slug' => 'managed-school']);
        $this->actingAs($user)->putJson('/superadmin/schools/'.$schoolTwo.'/status', ['status' => 'suspended'])->assertOk();
        $this->assertDatabaseHas('schools', ['id' => $schoolTwo, 'status' => 'suspended']);
        $this->assertDatabaseMissing('sessions', ['id' => 'managed-school-session']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $schoolTwo, 'module' => 'platform', 'action' => 'school_status_updated']);
        $this->assertStringContainsString('"revoked_sessions":1', (string) DB::table('school_audit')->where('school_id', $schoolTwo)->where('action', 'school_status_updated')->value('changes'));
    }

    public function test_superadmin_can_update_school_profile_with_unique_slug_validation(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Profile School', 'slug' => 'profile-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('schools')->insertGetId(['name' => 'Other Profile School', 'slug' => 'other-profile-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school, ['name' => 'Renamed School', 'slug' => 'renamed-school'])->assertOk();
        $this->assertDatabaseHas('schools', ['id' => $school, 'name' => 'Renamed School', 'slug' => 'renamed-school']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'school', 'entity_id' => $school, 'action' => 'school_updated']);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school, ['name' => 'Duplicate', 'slug' => 'other-profile-school'])->assertUnprocessable();
        $this->assertDatabaseHas('schools', ['id' => $other, 'slug' => 'other-profile-school']);
    }

    public function test_school_users_cannot_open_platform_dashboard(): void
    {
        $user = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);

        $this->actingAs($user)->get('/superadmin')->assertForbidden();
    }

    public function test_school_queries_are_scoped_to_the_users_membership(): void
    {
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Second School', 'slug' => 'second-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        DB::table('school_user')->where('user_id', $user->id)->delete();
        DB::table('school_user')->insert(['school_id' => $schoolTwo, 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => 1, 'name' => 'Test year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => 1, 'name' => 'Test class', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert([
            ['school_id' => 1, 'name' => 'First School Student', 'admission_number' => 'FIRST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $schoolTwo, 'name' => 'Second School Student', 'admission_number' => 'SECOND-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(TenantContext::class)->set($schoolTwo);
        $this->actingAs($user)->getJson('/portal/records/students')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.name', 'Second School Student');
    }

    public function test_people_access_cannot_list_or_update_users_from_another_school(): void
    {
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'People School', 'slug' => 'people-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $other = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        DB::table('school_user')->whereIn('user_id', [$admin->id, $other->id])->delete();
        DB::table('school_user')->insert([
            ['school_id' => $schoolTwo, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => 1, 'user_id' => $other->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(TenantContext::class)->set($schoolTwo);

        $this->actingAs($admin)->getJson('/portal/users')->assertOk()->assertJsonMissing(['id' => $other->id]);
        $this->putJson('/portal/users/'.$other->id, ['roles' => ['teacher'], 'is_active' => true])->assertNotFound();
    }

    public function test_branch_admin_cannot_auto_attach_an_unassigned_account_by_direct_url(): void
    {
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $unassigned = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);

        $this->actingAs($admin)->putJson('/portal/users/'.$unassigned->id, ['roles' => ['teacher'], 'is_active' => true])->assertForbidden();
        $this->assertDatabaseMissing('school_user_branches', ['user_id' => $unassigned->id]);
    }

    public function test_superadmin_can_create_a_branch_and_grant_scoped_admin_access(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch School', 'slug' => 'branch-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $branch = $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches', ['name' => 'North Campus', 'code' => 'north'])->assertCreated()->json('branch');
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/members/'.$admin->id.'/branch-access', ['branch_id' => $branch['id'], 'roles' => ['admin'], 'status' => 'active'])->assertOk();

        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch['id'], 'user_id' => $admin->id, 'roles' => json_encode(['admin'])]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $admin->id, 'action' => 'branch_access_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'branch', 'entity_id' => $branch['id'], 'action' => 'branch_created']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $admin->id, 'action' => 'branch_access_updated']);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch['id'], 'name' => 'Branch Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch['id'], 'name' => 'Branch Class', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $subject = DB::table('school_subjects')->insertGetId(['school_id' => $school, 'branch_id' => $branch['id'], 'name' => 'Branch Subject', 'code' => 'BR-SUB', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_teacher_assignments')->insert(['school_id' => $school, 'branch_id' => $branch['id'], 'user_id' => $teacher->id, 'class_id' => $class, 'subject_id' => $subject, 'status' => 'suspended', 'created_at' => now(), 'updated_at' => now()]);
        $branchDetail = collect($this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->json('branches'))->firstWhere('id', $branch['id']);
        $this->assertSame(1, $branchDetail['members']);
        $this->assertSame(0, $branchDetail['teachers']);
        $this->assertSame(0, $branchDetail['open_invoices']);
    }

    public function test_superadmin_can_attach_an_existing_account_to_a_school_branch(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Existing Account School', 'slug' => 'existing-account-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'New Campus', 'code' => 'new-campus', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $existing = User::factory()->create(['name' => 'Existing Client Admin', 'roles' => ['admin'], 'is_active' => true]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school)->assertOk()->assertJsonPath('available_users.0.id', $existing->id);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/members/'.$existing->id.'/branch-access', ['branch_id' => $branch, 'roles' => ['admin'], 'status' => 'active'])->assertOk();

        $this->assertDatabaseHas('school_user', ['school_id' => $school, 'user_id' => $existing->id, 'status' => 'active']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch, 'user_id' => $existing->id, 'roles' => json_encode(['admin'])]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $existing->id, 'action' => 'school_membership_created']);
    }

    public function test_superadmin_can_grant_branch_access_from_the_global_user_registry(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Global Access School', 'slug' => 'global-access-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Global Campus', 'code' => 'global', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Global Access Client', 'roles' => ['teacher'], 'is_active' => true]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id.'/branch-access', ['school_id' => $school, 'branch_id' => $branch, 'roles' => ['admin'], 'status' => 'active'])->assertOk();
        DB::table('sessions')->insert(['id' => 'global-access-session', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id.'/branch-access', ['school_id' => $school, 'branch_id' => $branch, 'roles' => ['owner'], 'status' => 'suspended'])->assertOk();

        $this->assertDatabaseHas('school_user', ['school_id' => $school, 'user_id' => $client->id, 'status' => 'active']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch, 'user_id' => $client->id, 'roles' => json_encode(['owner']), 'status' => 'suspended']);
        $this->assertDatabaseMissing('sessions', ['id' => 'global-access-session']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_branch_access_updated']);
        $this->assertStringContainsString('"revoked_sessions":1', (string) DB::table('school_audit')->where('school_id', $school)->where('record_id', $client->id)->where('action', 'branch_access_updated')->latest('id')->value('changes'));
    }

    public function test_database_rejects_a_branch_grant_whose_branch_belongs_to_another_school(): void
    {
        $schoolOne = DB::table('schools')->insertGetId(['name' => 'Integrity One', 'slug' => 'integrity-one', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Integrity Two', 'slug' => 'integrity-two', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $schoolTwo, 'name' => 'Two Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);

        $this->expectException(QueryException::class);
        DB::table('school_user_branches')->insert(['school_id' => $schoolOne, 'branch_id' => $branch, 'user_id' => $user->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_superadmin_can_override_the_assigned_plan_branch_limit(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Limit School', 'slug' => 'branch-limit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->updateOrInsert(['school_id' => $school], ['plan_id' => $plan, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_plans')->where('id', $plan)->update(['max_branches' => 1]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches', ['name' => 'First Branch', 'code' => 'first'])->assertCreated();
        $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches', ['name' => 'Over Limit', 'code' => 'over-limit'])->assertCreated();
        $this->assertSame(2, DB::table('school_branches')->where('school_id', $school)->count());
    }

    public function test_superadmin_can_update_branch_metadata_with_school_scoped_uniqueness(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Edit School', 'slug' => 'branch-edit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Old Campus', 'code' => 'old', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_branches')->insert(['school_id' => $school, 'name' => 'Other Campus', 'code' => 'other', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$branch, ['name' => 'North Campus', 'code' => 'north'])->assertOk();
        $this->assertDatabaseHas('school_branches', ['id' => $branch, 'name' => 'North Campus', 'code' => 'north']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'branch_id' => $branch, 'action' => 'branch_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'branch', 'entity_id' => $branch, 'action' => 'branch_updated']);
        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$branch, ['name' => 'Duplicate', 'code' => 'other'])->assertUnprocessable();
    }

    public function test_superadmin_can_change_the_active_default_branch_transactionally(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Default Branch School', 'slug' => 'default-branch-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $first = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'First', 'code' => 'first', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $second = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Second', 'code' => 'second', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$second.'/default')->assertOk();

        $this->assertDatabaseHas('school_branches', ['id' => $first, 'is_default' => false]);
        $this->assertDatabaseHas('school_branches', ['id' => $second, 'is_default' => true]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'branch_id' => $second, 'action' => 'branch_default_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'branch', 'entity_id' => $second, 'action' => 'branch_default_updated']);
    }

    public function test_superadmin_can_suspend_a_school_membership_and_all_branch_access(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Membership School', 'slug' => 'membership-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'branch-status-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/members/'.$admin->id.'/status', ['status' => 'suspended'])->assertOk()->assertJsonPath('revoked_sessions', 1);

        $this->assertDatabaseHas('school_user', ['school_id' => $school, 'user_id' => $admin->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch, 'user_id' => $admin->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $admin->id, 'action' => 'school_membership_status_updated']);
        $this->assertStringContainsString('"revoked_sessions":1', (string) DB::table('school_audit')->where('school_id', $school)->where('record_id', $admin->id)->where('action', 'school_membership_status_updated')->value('changes'));
    }

    public function test_superadmin_can_onboard_a_school_with_a_default_branch(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $school = $this->actingAs($superadmin)->postJson('/superadmin/schools', ['name' => 'Onboarded School', 'slug' => 'onboarded-school'])->assertCreated()->json('school');

        $this->assertDatabaseHas('school_branches', ['school_id' => $school['id'], 'code' => 'main', 'is_default' => true]);
        $this->assertDatabaseHas('school_subscriptions', ['school_id' => $school['id'], 'status' => 'trialing', 'plan_id' => DB::table('platform_plans')->where('code', 'starter')->value('id')]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school['id'], 'action' => 'school_created']);
    }

    public function test_superadmin_can_onboard_a_school_with_a_selected_active_plan(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);
        $plan = DB::table('platform_plans')->where('code', 'growth')->value('id');

        $school = $this->actingAs($superadmin)->postJson('/superadmin/schools', ['name' => 'Growth School', 'slug' => 'growth-school', 'plan_id' => $plan])->assertCreated()->json('school');

        $this->assertDatabaseHas('school_subscriptions', ['school_id' => $school['id'], 'plan_id' => $plan, 'status' => 'trialing']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school['id'], 'action' => 'school_created']);
    }

    public function test_superadmin_can_onboard_a_school_with_an_initial_admin_invitation_atomically(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/schools', ['name' => 'Onboarding Admin School', 'slug' => 'onboarding-admin-school', 'owner_name' => 'Initial Owner', 'owner_email' => 'initial-owner@example.test', 'owner_role' => 'owner'])->assertCreated();
        $school = $response->json('school');
        $invitation = $response->json('invitation');
        $this->assertNotNull($invitation['url']);
        $this->assertSame('initial-owner@example.test', $invitation['email']);
        $this->assertSame(['owner'], $invitation['roles']);
        $this->assertDatabaseHas('school_invitations', ['school_id' => $school['id'], 'branch_id' => $invitation['branch_id'], 'email' => 'initial-owner@example.test']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school['id'], 'action' => 'initial_admin_invitation_issued']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'school', 'entity_id' => $school['id'], 'action' => 'initial_admin_invitation_issued']);
    }

    public function test_superadmin_can_suspend_a_branch_with_audited_status_change(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Status School', 'slug' => 'branch-status-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'West', 'code' => 'west', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$branch.'/status', ['status' => 'suspended'])->assertOk();

        $this->assertDatabaseHas('school_branches', ['id' => $branch, 'status' => 'suspended']);
        $this->assertDatabaseMissing('sessions', ['id' => 'branch-status-session']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $branch, 'action' => 'branch_status_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'branch', 'entity_id' => $branch, 'action' => 'branch_status_updated']);
        $this->withSession(['school_id' => $school, 'branch_id' => $branch])->actingAs($admin)->getJson('/portal/meta')->assertForbidden();
    }

    public function test_superadmin_must_change_the_default_before_suspending_it(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Default Branch Safety School', 'slug' => 'default-branch-safety-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $default = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_branches')->insert(['school_id' => $school, 'name' => 'North', 'code' => 'north', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$default.'/status', ['status' => 'suspended'])->assertUnprocessable();

        $this->assertDatabaseHas('school_branches', ['id' => $default, 'status' => 'active', 'is_default' => 1]);
    }

    public function test_superadmin_can_issue_a_branch_admin_invitation_without_a_password(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Invitation School', 'slug' => 'invitation-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Central', 'code' => 'central', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches/'.$branch.'/invitations', ['name' => 'New Branch Admin', 'email' => 'new-branch-admin@example.test', 'roles' => ['admin']]);

        $response->assertCreated()->assertJsonStructure(['url', 'message']);
        $invitation = DB::table('school_invitations')->where('school_id', $school)->where('email', 'new-branch-admin@example.test')->first();
        $this->assertNotNull($invitation);
        $this->assertDatabaseHas('school_invitations', ['school_id' => $school, 'branch_id' => $branch, 'email' => 'new-branch-admin@example.test', 'accepted_at' => null]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $branch, 'action' => 'branch_invitation_issued']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'invitation', 'action' => 'branch_invitation_issued']);
        $this->actingAs($superadmin)->deleteJson('/superadmin/schools/'.$school.'/invitations/'.$invitation->id)->assertOk();
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $invitation->id, 'action' => 'invitation_revoked']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'invitation', 'entity_id' => $invitation->id, 'action' => 'invitation_revoked']);
    }

    public function test_superadmin_can_search_and_suspend_a_client_account_with_auditing(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Account School', 'slug' => 'account-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Client Admin', 'email' => 'client-admin@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'account-status-session', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/users?search=client-admin')->assertOk()->assertJsonPath('users.data.0.email', 'client-admin@example.test');
        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('revoked_sessions', 1);

        $this->assertDatabaseHas('users', ['id' => $client->id, 'is_active' => false]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'user_status_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_status_updated']);
        $this->assertStringContainsString('"revoked_sessions":1', (string) DB::table('platform_audit')->where('entity_id', $client->id)->where('action', 'user_status_updated')->value('changes'));
    }

    public function test_superadmin_can_control_an_unassigned_client_account(): void
    {
        $client = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id.'/status', ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'is_active' => false]);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_status_updated']);
    }

    public function test_superadmin_can_revoke_client_sessions_without_changing_account_status(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Session Control School', 'slug' => 'session-control-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert([
            ['id' => 'session-control-one', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'session-control-two', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->postJson('/superadmin/users/'.$client->id.'/sessions/revoke')->assertOk()->assertJsonPath('revoked_sessions', 2);
        $this->assertDatabaseMissing('sessions', ['user_id' => $client->id]);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'is_active' => true]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'user_sessions_revoked']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_sessions_revoked']);
    }

    public function test_superadmin_can_update_a_client_profile_with_auditing_and_session_revocation(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Profile Account School', 'slug' => 'profile-account-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Before Name', 'email' => 'before-profile@example.test', 'username' => 'before-profile', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id, ['name' => 'After Name', 'email' => 'after-profile@example.test', 'username' => 'after-profile'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'name' => 'After Name', 'email' => 'after-profile@example.test', 'username' => 'after-profile']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'user_profile_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_profile_updated']);
    }

    public function test_superadmin_can_issue_a_single_use_password_reset_link_for_a_client(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Recovery School', 'slug' => 'recovery-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['email' => 'recovery-client@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/users/'.$client->id.'/password-reset')->assertOk();
        $url = $response->json('url');
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $client->email]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'password_reset_issued']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'password_reset_issued']);

        $this->post('/logout');
        $this->get('/password/reset/'.$token)->assertOk();
        $this->post('/password/reset/'.$token, ['password' => 'New-recovery-password-123', 'password_confirmation' => 'New-recovery-password-123'])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $client->email]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'password_reset_completed']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'password_reset_completed']);
        $this->post('/password/reset/'.$token, ['password' => 'Another-password-123', 'password_confirmation' => 'Another-password-123'])->assertNotFound();
    }

    public function test_superadmin_can_inspect_branch_scoped_school_records_without_secrets(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Explorer School', 'slug' => 'explorer-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'East', 'code' => 'east', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $otherBranch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'West', 'code' => 'west', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $otherClass = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $otherBranch, 'name' => 'West Grade', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'East Student', 'admission_number' => 'EAST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'West Student', 'admission_number' => 'WEST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Cross Branch Student', 'admission_number' => 'CROSS-1', 'class_id' => $otherClass, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_expenses')->insert(['school_id' => $school, 'branch_id' => $branch, 'reference' => 'EXP-EAST-1', 'description' => 'Campus supplies', 'category' => 'supplies', 'amount' => 2500, 'paid_on' => '2026-09-19', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notices')->insert(['school_id' => $school, 'branch_id' => $branch, 'title' => 'East notice', 'body' => 'Operational notice', 'audience' => 'all', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch);

        $response->assertOk()->assertJsonPath('records.data.0.name', 'Cross Branch Student')->assertJsonMissingPath('records.data.0.password');
        $crossBranchStudent = collect($response->json('records.data'))->firstWhere('name', 'Cross Branch Student');
        $this->assertNull($crossBranchStudent['class_name']);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch.'&per_page=1&page=2')->assertOk()->assertJsonPath('records.total', 3)->assertJsonPath('records.last_page', 3)->assertJsonPath('records.data.0.name', 'East Student');
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/expenses?branch_id='.$branch)->assertOk()->assertJsonPath('records.data.0.reference', 'EXP-EAST-1');
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/notices?branch_id='.$branch)->assertOk()->assertJsonPath('records.data.0.title', 'East notice');
    }

    public function test_branch_roles_do_not_leak_to_another_branch(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Scoped School', 'slug' => 'scoped-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branchOne = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'One', 'code' => 'one', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branchTwo = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Two', 'code' => 'two', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        $tenant = app(TenantContext::class);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branchOne, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $tenant->set($school, $branchOne);
        $this->assertTrue($tenant->hasRole($admin, 'admin'));
        $tenant->set($school, $branchTwo);
        $this->assertFalse($tenant->hasRole($admin, 'admin'));
    }

    public function test_branch_filtered_user_explorer_excludes_members_without_that_branch_grant(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'User Explorer School', 'slug' => 'user-explorer-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branchOne = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'One', 'code' => 'one', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branchTwo = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Two', 'code' => 'two', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $branchOneUser = User::factory()->create(['email' => 'branch-one-user@example.test', 'roles' => ['admin'], 'is_active' => true]);
        $branchTwoUser = User::factory()->create(['email' => 'branch-two-user@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert([
            ['school_id' => $school, 'user_id' => $branchOneUser->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'user_id' => $branchTwoUser->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('school_user_branches')->insert([
            ['school_id' => $school, 'branch_id' => $branchOne, 'user_id' => $branchOneUser->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'branch_id' => $branchTwo, 'user_id' => $branchTwoUser->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/users?branch_id='.$branchOne)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.email', 'branch-one-user@example.test');
    }

    public function test_branch_admin_can_list_and_switch_only_assigned_contexts(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Context School', 'slug' => 'context-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branchOne = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'One', 'code' => 'one', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branchTwo = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Two', 'code' => 'two', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert([
            ['school_id' => $school, 'branch_id' => $branchOne, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'branch_id' => $branchTwo, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($admin)->getJson('/portal/contexts')->assertOk()->assertJsonCount(2, 'contexts');
        $this->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $branchTwo])->assertOk()->assertJsonPath('current.branch_id', $branchTwo);
        $this->assertSame($branchTwo, (int) session('branch_id'));
        $this->putJson('/portal/context', ['school_id' => $school, 'branch_id' => 999999])->assertForbidden();
    }
}
