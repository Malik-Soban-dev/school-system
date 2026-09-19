<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $branchTables = [
        'school_academic_years', 'school_classes', 'school_subjects', 'school_staff',
        'school_students', 'school_guardian_links', 'school_teacher_assignments', 'school_attendance',
        'school_timetables', 'school_exams', 'school_grades', 'school_invoices', 'school_payments',
        'school_expenses', 'school_leave_requests', 'school_payroll', 'school_payroll_payments',
        'school_notices', 'school_enrollments', 'school_invitations', 'school_notification_events',
        'school_notifications', 'school_notification_deliveries', 'school_notification_preferences',
        'school_exam_subjects', 'school_grade_bands',
    ];

    public function up(): void
    {
        Schema::create('school_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('school_user_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('school_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('roles');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
            $table->index(['school_id', 'user_id', 'status']);
        });

        foreach (DB::table('schools')->orderBy('id')->get(['id', 'name']) as $school) {
            $branchId = DB::table('school_branches')->insertGetId([
                'school_id' => $school->id,
                'name' => $school->name.' Main Branch',
                'code' => 'main',
                'status' => 'active',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($this->branchTables as $tableName) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    Schema::table($tableName, fn (Blueprint $table): mixed => $table->unsignedBigInteger('branch_id')->nullable()->index());
                }
                DB::table($tableName)->where('school_id', $school->id)->whereNull('branch_id')->update(['branch_id' => $branchId]);
            }

            DB::table('school_user as membership')
                ->where('membership.school_id', $school->id)
                ->join('users', 'users.id', '=', 'membership.user_id')
                ->where('membership.status', 'active')
                ->whereJsonDoesntContain('users.roles', 'superadmin')
                ->select('membership.user_id')
                ->orderBy('membership.user_id')
                ->get()
                ->each(fn (object $membership): int => DB::table('school_user_branches')->insertOrIgnore([
                    'school_id' => $school->id,
                    'branch_id' => $branchId,
                    'user_id' => $membership->user_id,
                    'roles' => DB::table('users')->where('id', $membership->user_id)->value('roles') ?: '[]',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_user_branches');
        Schema::dropIfExists('school_branches');

        foreach (array_reverse($this->branchTables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'branch_id')) {
                Schema::table($tableName, fn (Blueprint $table): mixed => $table->dropColumn('branch_id'));
            }
        }
    }
};
