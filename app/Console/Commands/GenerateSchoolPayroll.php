<?php

namespace App\Console\Commands;

use App\Services\SchoolNotifications;
use App\Support\SchoolEntitlements;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('school:payroll {--month= : Generate payroll for this YYYY-MM month}')]
#[Description('Create repeat-safe monthly payroll calculations for configured active staff')]
class GenerateSchoolPayroll extends Command
{
    public function handle(): int
    {
        $month = CarbonImmutable::createFromFormat('Y-m', $this->option('month') ?: today()->format('Y-m'))->format('Y-m');
        $created = 0;
        $skipped = 0;

        foreach (DB::table('schools')->where('status', 'active')->orderBy('id')->pluck('id') as $schoolId) {
            foreach (DB::table('school_branches')->where('school_id', $schoolId)->where('status', 'active')->orderBy('id')->pluck('id') as $branchId) {
                $tenant = app(TenantContext::class);
                $tenant->set((int) $schoolId, (int) $branchId);
                if (! app(SchoolEntitlements::class)->allowsForSchool((int) $schoolId, 'payroll')) {
                    continue;
                }

                $now = now();
                $tenant->table('school_staff')->where('status', 'active')->orderBy('id')->chunkById(100, function ($staff) use ($tenant, $month, $now, &$created, &$skipped): void {
                    foreach ($staff as $member) {
                        $basic = (int) ($member->basic_salary ?? 0);
                        $allowances = (int) ($member->monthly_allowances ?? 0);
                        $deductions = (int) ($member->monthly_deductions ?? 0);
                        if ($basic + $allowances <= 0 || $deductions > $basic + $allowances) {
                            $skipped++;
                            continue;
                        }
                        $inserted = DB::table('school_payroll')->insertOrIgnore([
                            'school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'staff_id' => $member->id,
                            'month' => $month, 'basic' => $basic, 'allowances' => $allowances, 'deductions' => $deductions,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        if ($inserted !== 1) {
                            $skipped++;
                            continue;
                        }
                        $created++;
                        $payroll = $tenant->table('school_payroll')->where('staff_id', $member->id)->where('month', $month)->first(['id']);
                        DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => null, 'module' => 'payroll', 'record_id' => $payroll->id, 'action' => 'monthly_payroll_created', 'changes' => json_encode(['month' => $month, 'staff_id' => $member->id]), 'created_at' => $now]);
                        app(SchoolNotifications::class)->enqueue('payroll', $payroll->id, ['month' => $month]);
                    }
                });
            }
        }

        $this->info("Created {$created} payroll calculation(s); skipped {$skipped} staff record(s).");

        return self::SUCCESS;
    }
}
