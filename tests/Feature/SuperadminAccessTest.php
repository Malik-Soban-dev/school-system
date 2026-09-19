<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperadminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_platform_dashboard(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->get('/superadmin')->assertOk()->assertSee('Superadmin dashboard');
    }

    public function test_superadmin_can_review_platform_health_without_backup_contents(): void
    {
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->getJson('/superadmin/health')->assertOk()->assertJsonPath('database', 'ok')->assertJsonStructure(['status', 'queue' => ['pending', 'failed'], 'schools' => ['active', 'suspended'], 'backups']);
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

    public function test_superadmin_can_update_a_plan_with_platform_auditing(): void
    {
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/plans/'.$plan, ['name' => 'Starter Plus', 'monthly_price_cents' => 5900, 'max_branches' => 2, 'max_students' => 500, 'features' => ['attendance', 'grades', 'invoices'], 'status' => 'active'])->assertOk();

        $this->assertDatabaseHas('platform_plans', ['id' => $plan, 'name' => 'Starter Plus', 'monthly_price_cents' => 5900, 'max_students' => 500]);
        $this->assertDatabaseHas('platform_audit', ['entity_type' => 'plan', 'entity_id' => $plan, 'action' => 'plan_updated']);
        $this->actingAs($superadmin)->getJson('/superadmin/data')->assertOk()->assertJsonFragment(['action' => 'plan_updated', 'school_name' => 'Platform']);
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

    public function test_branch_creation_respects_the_assigned_plan_limit(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Branch Limit School', 'slug' => 'branch-limit-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('platform_plans')->where('code', 'starter')->value('id');
        DB::table('school_subscriptions')->updateOrInsert(['school_id' => $school], ['plan_id' => $plan, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_plans')->where('id', $plan)->update(['max_branches' => 1]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches', ['name' => 'First Branch', 'code' => 'first'])->assertCreated();
        $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches', ['name' => 'Over Limit', 'code' => 'over-limit'])->assertStatus(422);
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
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($superadmin)->putJson('/superadmin/schools/'.$school.'/branches/'.$branch.'/status', ['status' => 'suspended'])->assertOk();

        $this->assertDatabaseHas('school_branches', ['id' => $branch, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $branch, 'action' => 'branch_status_updated']);
    }

    public function test_superadmin_can_issue_a_branch_admin_invitation_without_a_password(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Invitation School', 'slug' => 'invitation-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'Central', 'code' => 'central', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->postJson('/superadmin/schools/'.$school.'/branches/'.$branch.'/invitations', ['name' => 'New Branch Admin', 'email' => 'new-branch-admin@example.test', 'roles' => ['admin']]);

        $response->assertCreated()->assertJsonStructure(['url', 'message']);
        $this->assertDatabaseHas('school_invitations', ['school_id' => $school, 'branch_id' => $branch, 'email' => 'new-branch-admin@example.test', 'accepted_at' => null]);
        $this->assertDatabaseHas('school_audit', ['school_id' => $school, 'record_id' => $branch, 'action' => 'branch_invitation_issued']);
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
    }

    public function test_superadmin_can_inspect_branch_scoped_school_records_without_secrets(): void
    {
        $school = DB::table('schools')->insertGetId(['name' => 'Explorer School', 'slug' => 'explorer-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('school_branches')->insertGetId(['school_id' => $school, 'name' => 'East', 'code' => 'east', 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('school_academic_years')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['school_id' => $school, 'branch_id' => $branch, 'name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_students')->insert(['school_id' => $school, 'branch_id' => $branch, 'name' => 'East Student', 'admission_number' => 'EAST-1', 'class_id' => $class, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_expenses')->insert(['school_id' => $school, 'branch_id' => $branch, 'reference' => 'EXP-EAST-1', 'description' => 'Campus supplies', 'category' => 'supplies', 'amount' => 2500, 'paid_on' => '2026-09-19', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('school_notices')->insert(['school_id' => $school, 'branch_id' => $branch, 'title' => 'East notice', 'body' => 'Operational notice', 'audience' => 'all', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch);

        $response->assertOk()->assertJsonPath('records.data.0.name', 'East Student')->assertJsonMissingPath('records.data.0.password');
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
