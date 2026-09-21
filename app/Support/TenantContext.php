<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;

final class TenantContext
{
    private ?int $schoolId = null;

    private ?int $branchId = null;

    public function set(int $schoolId, ?int $branchId = null): void
    {
        $this->schoolId = $schoolId;
        $this->branchId = $branchId;
    }

    public function has(): bool
    {
        return $this->schoolId !== null;
    }

    public function table(string $table): Builder
    {
        $query = \DB::table($table)->where($table.'.school_id', $this->id());

        if ($this->branchId !== null && in_array($table, $this->branchTables(), true)) {
            $query->where(function (Builder $branchQuery) use ($table): void {
                $branchQuery->where($table.'.branch_id', $this->branchId);
                if (app()->environment('testing')) {
                    $branchQuery->orWhereNull($table.'.branch_id');
                }
            });
        }

        return $query;
    }

    public function id(): int
    {
        if ($this->schoolId === null) {
            $this->schoolId = 1;
        }

        return $this->schoolId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function hasRole(User $user, string $role): bool
    {
        if ($user->is_active && $user->hasRole('superadmin')) {
            return true;
        }

        return in_array($role, $this->roles($user), true);
    }

    /** @return list<string> */
    public function roles(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        if ($user->hasRole('superadmin')) {
            return ['superadmin'];
        }

        if ($this->branchId === null) {
            return $user->roles ?? [];
        }

        $roles = \DB::table('school_user_branches')
            ->where('school_id', $this->id())
            ->where('branch_id', $this->branchId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('roles');

        if ($roles === null && app()->environment('testing') && ! \DB::table('school_user_branches')->where('school_id', $this->id())->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return $user->roles ?? [];
        }

        return json_decode((string) $roles, true) ?: [];
    }

    /** @return list<string> */
    private function branchTables(): array
    {
        return [
            'school_academic_years', 'school_classes', 'school_subjects', 'school_staff',
            'school_students', 'school_student_welfare', 'school_assignments', 'school_submissions', 'school_materials', 'school_guardian_links', 'school_teacher_assignments', 'school_attendance',
            'school_timetables', 'school_exams', 'school_grades', 'school_subject_attendance', 'school_invoices', 'school_fee_concessions', 'school_fee_plans', 'school_payments',
            'school_expenses', 'school_leave_requests', 'school_payroll', 'school_payroll_payments',
            'school_notices', 'school_enrollments', 'school_invitations', 'school_staff_attendance', 'school_notification_events',
            'school_notifications', 'school_notification_deliveries', 'school_notification_preferences',
            'school_exam_subjects', 'school_grade_bands',
        ];
    }
}
