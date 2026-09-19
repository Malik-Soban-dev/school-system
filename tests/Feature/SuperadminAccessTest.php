<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use App\Support\Totp;
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

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'mfa.superadmin', 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->get('/superadmin')->assertOk();
    }

    public function test_superadmin_can_open_platform_dashboard(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->get('/superadmin')->assertOk()->assertSee('Superadmin dashboard');
    }

    public function test_superadmin_can_switch_into_any_branch_workspace_and_use_full_portal_authority(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Workspace School', 'slug' => 'workspace-school', 'status' => 'suspended', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Workspace Campus', 'code' => 'workspace', 'status' => 'suspended', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/portal/context', ['school_id' => $school, 'branch_id' => $branch])->assertOk()->assertJsonPath('current.branch_id', $branch);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'workspace', 'entity_id' => $branch, 'action' => 'superadmin_context_switched']);

        $this->actingAs($superadmin)->getJson('/portal/meta')->assertOk()->assertJsonPath('current_context.school_id', $school)->assertJsonPath('current_context.branch_id', $branch)->assertJsonPath('canManage', true);
        $this->actingAs($superadmin)->postJson('/portal/records/notices', ['title' => 'Platform notice', 'body' => 'Managed centrally', 'audience' => 'all', 'status' => 'published'])->assertOk();
        $this->assertDatabaseHas('school_notices', ['school_id' => $school, 'branch_id' => $branch, 'title' => 'Platform notice']);
    }

    public function test_superadmin_can_review_a_paginated_cross_school_branch_registry(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Registry School', 'slug' => 'branch-registry-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_branches')->insert([
            ['school_id' => $school, 'name' => 'North Branch', 'code' => 'north', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'name' => 'South Branch', 'code' => 'south', 'status' => 'suspended', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/branches?status=active&search=North&per_page=1')->assertOk()->assertJsonPath('branches.total', 1)->assertJsonPath('branches.data.0.school_name', 'Branch Registry School')->assertJsonPath('branches.data.0.name', 'North Branch')->assertJsonPath('branches.data.0.students', 0);
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
    }

    public function test_superadmin_can_review_platform_health_without_backup_contents(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->getJson('/superadmin/health')->assertOk()->assertJsonPath('database', 'ok')->assertJsonStructure(['status', 'queue' => ['pending', 'failed'], 'schools' => ['active', 'suspended'], 'backups']);
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
        $notification = DB::table('school_notifications')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $superadmin->id, 'event_key' => 'explorer-test', 'module' => 'exams', 'record_id' => $exam, 'title' => 'Exam reminder', 'body' => 'Reminder body', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notification_deliveries')->insert(['school_id' => $school, 'branch_id' => $branch, 'notification_id' => $notification, 'channel' => 'email', 'status' => 'failed', 'attempts' => 2, 'available_at' => now(), 'error_code' => 'provider_error', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/settings')->assertOk()->assertJsonPath('records.data.0.value', '[redacted asset]')->assertJsonMissing(['value' => 'private-image-data']);
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

        $this->assertDatabaseHas('school_subscriptions', ['school_id' => $school, 'plan_id' => $plan, 'status' => 'active', 'renews_at' => '2027-01-01']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'module' => 'billing', 'action' => 'subscription_updated']);
    }

    public function test_platform_summary_reports_billing_metrics(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Metrics School', 'slug' => 'metrics-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->first(['id', 'monthly_price_cents']);
        DB::table('school_subscriptions')->insert(['school_id' => $school, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->assertJsonPath('billing.mrr_cents', (int) $plan->monthly_price_cents)->assertJsonPath('billing.subscriptions.active', 1)->assertJsonFragment(['code' => 'starter', 'name' => 'Starter', 'total' => 1]);
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

        $plan = $this->actingAs($superadmin)->postJson('/superadmin/plans', ['code' => 'pro-plus', 'name' => 'Pro Plus', 'monthly_price_cents' => 24900, 'max_branches' => 10, 'max_students' => 5000, 'features' => ['attendance', 'grades', 'payroll']])->assertCreated()->json('plan');

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
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->getJson('/superadmin/data')->assertOk()->assertJsonPath('summary.schools', 2)->assertJsonFragment(['slug' => 'managed-school']);
        $this->actingAs($user)->putJson('/superadmin/schools/'.$schoolTwo.'/status', ['status' => 'suspended'])->assertOk();
        $this->assertDatabaseHas('schools', ['id' => $schoolTwo, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $schoolTwo, 'module' => 'platform', 'action' => 'school_status_updated', 'changes' => json_encode(['before' => ['status' => 'active'], 'after' => ['status' => 'suspended']])]);
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
    }

    public function test_superadmin_can_suspend_a_school_membership_and_all_branch_access(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Membership School', 'slug' => 'membership-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Main', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $admin->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_user_branches')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $admin->id, 'roles' => json_encode(['admin']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/members/'.$admin->id.'/status', ['status' => 'suspended'])->assertOk();

        $this->assertDatabaseHas('school_user', ['school_id' => $school, 'user_id' => $admin->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_user_branches', ['school_id' => $school, 'branch_id' => $branch, 'user_id' => $admin->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $admin->id, 'action' => 'school_membership_status_updated']);
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
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $branch, 'action' => 'branch_status_updated']);
        $this->withSession(['school_id' => $school, 'branch_id' => $branch])->actingAs($admin)->getJson('/portal/meta')->assertForbidden();
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
        $this->actingAs($superadmin)->deleteJson('/superadmin/schools/'.$school.'/invitations/'.$invitation->id)->assertOk();
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $invitation->id, 'action' => 'invitation_revoked']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'invitation', 'entity_id' => $invitation->id, 'action' => 'invitation_revoked']);
    }

    public function test_superadmin_can_search_and_suspend_a_client_account_with_auditing(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Account School', 'slug' => 'account-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $client = User::factory()->create(['name' => 'Client Admin', 'email' => 'client-admin@example.test', 'roles' => ['admin'], 'is_active' => true]);
        DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $client->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->getJson('/superadmin/users?search=client-admin')->assertOk()->assertJsonPath('users.data.0.email', 'client-admin@example.test');
        $this->actingAs($superadmin)->putJson('/superadmin/users/'.$client->id.'/status', ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'is_active' => false]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $client->id, 'action' => 'user_status_updated']);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'user_status_updated']);
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
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'East Student', 'admission_number' => 'EAST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'West Student', 'admission_number' => 'WEST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_expenses')->insert(['school_id' => $school, 'branch_id' => $branch, 'reference' => 'EXP-EAST-1', 'description' => 'Campus supplies', 'category' => 'supplies', 'amount' => 2500, 'paid_on' => '2026-09-19', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notices')->insert(['school_id' => $school, 'branch_id' => $branch, 'title' => 'East notice', 'body' => 'Operational notice', 'audience' => 'all', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch);

        $response->assertOk()->assertJsonPath('records.data.0.name', 'East Student')->assertJsonMissingPath('records.data.0.password');
        $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch.'&per_page=1&page=2')->assertOk()->assertJsonPath('records.total', 2)->assertJsonPath('records.last_page', 2)->assertJsonPath('records.data.0.name', 'West Student');
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
