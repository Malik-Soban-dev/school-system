<?php

namespace App\Console\Commands;

use App\Services\SchoolNotifications;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('school:late-fees {--until= : Apply late fees as of this date}')]
#[Description('Create repeat-safe late-fee invoices for overdue school fees')]
class GenerateSchoolLateFees extends Command
{
    public function handle(): int
    {
        $until = CarbonImmutable::parse($this->option('until') ?: today()->toDateString());
        $created = 0;
        $skipped = 0;

        foreach (DB::table('schools')->where('status', 'active')->orderBy('id')->pluck('id') as $schoolId) {
            foreach (DB::table('school_branches')->where('school_id', $schoolId)->where('status', 'active')->orderBy('id')->pluck('id') as $branchId) {
                app(TenantContext::class)->set((int) $schoolId, (int) $branchId);
                $amount = (int) (app(TenantContext::class)->table('school_settings')->where('key', 'late_fee_amount')->value('value') ?: 0);
                $graceDays = max(0, (int) (app(TenantContext::class)->table('school_settings')->where('key', 'late_fee_grace_days')->value('value') ?: 0));
                if ($amount < 1) {
                    continue;
                }

                app(TenantContext::class)->table('school_invoices')->whereDate('due_on', '<', $until->subDays($graceDays)->toDateString())->orderBy('id')->chunkById(100, function ($invoices) use ($until, $amount, &$created, &$skipped): void {
                    foreach ($invoices as $invoice) {
                        $paid = (int) app(TenantContext::class)->table('school_payments')->where('invoice_id', $invoice->id)->sum('amount');
                        if ($paid >= (int) $invoice->amount) {
                            $skipped++;
                            continue;
                        }
                        $reference = 'LATE-'.$invoice->id.'-'.$until->format('Ym');
                        $now = now();
                        DB::transaction(function () use ($invoice, $reference, $amount, $until, $now, &$created): void {
                            $inserted = DB::table('school_invoices')->insertOrIgnore([
                                'school_id' => app(TenantContext::class)->id(),
                                'branch_id' => app(TenantContext::class)->branchId(),
                                'reference' => $reference,
                                'student_id' => $invoice->student_id,
                                'description' => 'Late fee for '.$invoice->reference,
                                'amount' => $amount,
                                'due_on' => $until->toDateString(),
                                'billing_month' => $invoice->billing_month ?: $until->format('Y-m'),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            if ($inserted !== 1) {
                                return;
                            }
                            $created++;
                            $lateInvoice = app(TenantContext::class)->table('school_invoices')->where('reference', $reference)->first(['id']);
                            DB::table('school_audit')->insert(['school_id' => app(TenantContext::class)->id(), 'branch_id' => app(TenantContext::class)->branchId(), 'user_id' => null, 'module' => 'invoices', 'record_id' => $lateInvoice->id, 'action' => 'late_fee_created', 'changes' => json_encode(['original_invoice_id' => $invoice->id, 'amount' => $amount, 'as_of' => $until->toDateString()]), 'created_at' => $now]);
                            app(SchoolNotifications::class)->enqueue('invoices', $lateInvoice->id, ['reference' => $reference, 'late_fee' => true]);
                        });
                    }
                });
            }
        }

        $this->info("Created {$created} late-fee invoice(s); skipped {$skipped} paid or duplicate fee(s).");

        return self::SUCCESS;
    }
}
