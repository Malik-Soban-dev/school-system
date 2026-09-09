<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolMigrationRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rerunning_interrupted_migration_repairs_indexes_and_preserves_data(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'roles' => ['owner']]);
        DB::table('school_academic_years')->insert(['name' => 'Existing school year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        DB::statement('DROP INDEX school_invoices_reference_unique');
        $migration = require database_path('migrations/2026_09_09_061421_create_school_operations_tables.php');
        $migration->up();
        $this->assertDatabaseHas('school_academic_years', ['name' => 'Existing school year']);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => true]);
        $this->assertTrue(Schema::hasIndex('school_invoices', 'school_invoices_reference_unique'));
        $this->assertTrue(Schema::hasColumn('users', 'tutorials'));
    }
}
