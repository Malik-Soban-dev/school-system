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
