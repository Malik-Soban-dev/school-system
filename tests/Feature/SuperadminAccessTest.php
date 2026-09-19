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

    public function test_superadmin_can_review_and_suspend_a_school_with_audited_status_change(): void
    {
        $schoolTwo = DB::table('schools')->insertGetId(['name' => 'Managed School', 'slug' => 'managed-school', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $this->actingAs($user)->getJson('/superadmin/data')->assertOk()->assertJsonPath('summary.schools', 2)->assertJsonFragment(['slug' => 'managed-school']);
        $this->actingAs($user)->putJson('/superadmin/schools/'.$schoolTwo.'/status', ['status' => 'suspended'])->assertOk();
        $this->assertDatabaseHas('schools', ['id' => $schoolTwo, 'status' => 'suspended']);
        $this->assertDatabaseHas('school_audit', ['school_id' => $schoolTwo, 'module' => 'platform', 'action' => 'school_status_updated', 'changes' => json_encode(['before' => ['status' => 'active'], 'after' => ['status' => 'suspended']])]);
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

    public function test_superadmin_can_onboard_a_school_with_a_default_branch(): void
    {
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $school = $this->actingAs($superadmin)->postJson('/superadmin/schools', ['name' => 'Onboarded School', 'slug' => 'onboarded-school'])->assertCreated()->json('school');

        $this->assertDatabaseHas('school_branches', ['school_id' => $school['id'], 'code' => 'main', 'is_default' => true]);
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
        $superadmin = User::factory()->create(['roles' => ['superadmin'], 'is_active' => true]);

        $response = $this->actingAs($superadmin)->getJson('/superadmin/schools/'.$school.'/records/students?branch_id='.$branch);

        $response->assertOk()->assertJsonPath('records.data.0.name', 'East Student')->assertJsonMissingPath('records.data.0.password');
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
}
