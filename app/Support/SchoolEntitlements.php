<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SchoolEntitlements
{
    public const FEATURES = ['attendance', 'grades', 'invoices', 'payroll', 'notifications', 'branches'];

    public function __construct(private TenantContext $tenant) {}

    public function plan(): ?object
    {
        return \DB::table('school_subscriptions as subscription')
            ->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')
            ->where('subscription.school_id', $this->tenant->id())
            ->first(['plan.id', 'plan.code', 'plan.features', 'plan.max_students', 'subscription.status as subscription_status']);
    }

    public function allows(string $feature): bool
    {
        return $this->allowsForSchool($this->tenant->id(), $feature);
    }

    public function allowsForSchool(int $schoolId, string $feature): bool
    {
        if (auth()->user()?->hasRole('superadmin')) {
            return true;
        }

        $plan = \DB::table('school_subscriptions as subscription')
            ->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')
            ->where('subscription.school_id', $schoolId)
            ->first(['plan.features', 'subscription.status as subscription_status']);

        if ($plan?->subscription_status === 'canceled') {
            return false;
        }

        if (Schema::hasTable('school_feature_overrides')) {
            $override = \DB::table('school_feature_overrides')->where('school_id', $schoolId)->where('feature', $feature)->value('enabled');
            if ($override !== null) {
                return (bool) $override;
            }
        }

        if (! $plan) {
            return $plan === null;
        }
        $features = json_decode((string) $plan->features, true) ?: [];

        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    public function assertFeature(string $feature): void
    {
        if (! $this->allows($feature)) {
            throw new HttpException(403, 'This module is not included in the school subscription. Ask the platform Superadmin to upgrade the plan.');
        }
    }

    public function featureForModule(string $module): ?string
    {
        return match ($module) {
            'attendance' => 'attendance',
            'exams', 'exam_subjects', 'grades', 'grade_bands' => 'grades',
            'invoices', 'payments' => 'invoices',
            'payroll', 'payroll_payments' => 'payroll',
            default => null,
        };
    }
}
