<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BuildPlatformSchoolExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<string> */
    private const SCHOOL_TABLES = [
        'school_branches', 'school_user', 'school_user_branches', 'school_academic_years', 'school_classes', 'school_subjects',
        'school_staff', 'school_students', 'school_student_welfare', 'school_assignments', 'school_submissions', 'school_materials', 'school_guardian_links', 'school_teacher_assignments', 'school_attendance',
        'school_timetables', 'school_exams', 'school_grades', 'school_subject_attendance', 'school_invoices', 'school_fee_concessions', 'school_fee_plans', 'school_payments', 'school_expenses',
        'school_leave_requests', 'school_payroll', 'school_payroll_payments', 'school_notices', 'school_enrollments',
        'school_invitations', 'school_notification_events', 'school_notifications', 'school_notification_deliveries',
        'school_notification_preferences', 'school_exam_subjects', 'school_grade_bands', 'school_settings', 'school_audit',
        'school_subscriptions', 'school_feature_overrides', 'platform_billing_invoices',
    ];

    /** @var list<string> */
    private const SENSITIVE_COLUMNS = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'token_hash', 'whatsapp_phone'];

    public function __construct(public int $exportId, public int $schoolId) {}

    public function handle(): void
    {
        $export = DB::table('platform_exports')->where('id', $this->exportId)->where('type', 'school')->where('school_id', $this->schoolId)->first(['id', 'requested_by']);
        if (! $export) {
            return;
        }
        $school = DB::table('schools')->where('id', $this->schoolId)->first(['id', 'name', 'slug', 'status']);
        if (! $school) {
            return;
        }

        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'processing', 'updated_at' => now()]);
        $relativePath = 'exports/school-'.$this->schoolId.'-'.$this->exportId.'.ndjson';
        $path = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('The school export file could not be opened.');
        }

        $rows = 0;
        $this->writeLine($handle, ['table' => '_meta', 'record' => ['school' => (array) $school, 'format' => 'ndjson', 'generated_at' => now()->toIso8601String(), 'secrets_excluded' => true]]);
        foreach (self::SCHOOL_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                continue;
            }
            $rows += $this->writeSchoolTable($handle, $table);
        }
        $rows += $this->writeUsers($handle);
        fclose($handle);

        $expiresAt = now()->addDays(7);
        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'completed', 'file_path' => $relativePath, 'row_count' => $rows, 'expires_at' => $expiresAt, 'updated_at' => now()]);
        DB::table('platform_audit')->insert(['user_id' => $export->requested_by, 'school_id' => $this->schoolId, 'entity_type' => 'export', 'entity_id' => $this->exportId, 'action' => 'school_export_completed', 'changes' => json_encode(['school_id' => $this->schoolId, 'rows' => $rows, 'expires_at' => $expiresAt->toIso8601String()]), 'created_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        $export = DB::table('platform_exports')->where('id', $this->exportId)->first(['id', 'requested_by', 'type']);
        File::delete(storage_path('app/private/exports/school-'.$this->schoolId.'-'.$this->exportId.'.ndjson'));
        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'failed', 'file_path' => null, 'expires_at' => null, 'error' => substr($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
        if ($export) {
            DB::table('platform_audit')->insert(['user_id' => $export->requested_by, 'school_id' => $this->schoolId, 'entity_type' => 'export', 'entity_id' => $this->exportId, 'action' => 'school_export_failed', 'changes' => json_encode(['type' => $export->type, 'school_id' => $this->schoolId]), 'created_at' => now()]);
        }
    }

    private function writeSchoolTable($handle, string $table): int
    {
        $columns = array_values(array_diff(Schema::getColumnListing($table), self::SENSITIVE_COLUMNS));
        if ($table === 'school_settings') {
            $columns = array_values(array_diff($columns, ['value']));
        }
        $rows = 0;
        $query = DB::table($table)->where('school_id', $this->schoolId)->select($columns);
        $callback = function ($records) use ($handle, $table, &$rows): void {
            foreach ($records as $record) {
                $this->writeLine($handle, ['table' => $table, 'record' => (array) $record]);
                $rows++;
            }
        };
        if (in_array('id', $columns, true)) {
            $query->orderBy('id')->chunkById(500, $callback, 'id', 'id');
        } else {
            $query->orderBy($columns[0] ?? 'school_id')->chunk(500, $callback);
        }

        return $rows;
    }

    private function writeUsers($handle): int
    {
        $columns = ['id', 'name', 'username', 'email', 'is_active', 'roles', 'created_at', 'updated_at'];
        $rows = 0;
        DB::table('users as u')->where(function ($query): void {
            $query->whereExists(fn ($exists) => $exists->selectRaw('1')->from('school_user as membership')->whereColumn('membership.user_id', 'u.id')->where('membership.school_id', $this->schoolId))
                ->orWhereExists(fn ($exists) => $exists->selectRaw('1')->from('school_user_branches as access')->whereColumn('access.user_id', 'u.id')->where('access.school_id', $this->schoolId));
        })->select(array_map(fn (string $column): string => 'u.'.$column, $columns))->orderBy('u.id')->chunkById(500, function ($records) use ($handle, &$rows): void {
            foreach ($records as $record) {
                $this->writeLine($handle, ['table' => 'users', 'record' => (array) $record]);
                $rows++;
            }
        }, 'u.id', 'id');

        return $rows;
    }

    private function writeLine($handle, array $line): void
    {
        fwrite($handle, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
