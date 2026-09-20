<?php

namespace App\Console\Commands;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('school:monthly-invoices {--month= : Generate invoices for this YYYY-MM month}')]
#[Description('Create repeat-safe monthly fee invoices for active students')]
class GenerateSchoolMonthlyInvoices extends Command
{
    public function handle(): int
    {
        $month = CarbonImmutable::createFromFormat('Y-m', $this->option('month') ?: today()->format('Y-m'))->startOfMonth();
        $created = 0;
        $skipped = 0;

        foreach (DB::table('schools')->where('status', 'active')->orderBy('id')->pluck('id') as $schoolId) {
            foreach (DB::table('school_branches')->where('school_id', $schoolId)->where('status', 'active')->orderBy('id')->pluck('id') as $branchId) {
                $tenant = app(TenantContext::class);
                $tenant->set((int) $schoolId, (int) $branchId);
                $settings = $tenant->table('school_settings')->whereIn('key', ['monthly_fee_amount', 'monthly_fee_due_day', 'monthly_fee_description'])->pluck('value', 'key');
                $amount = (int) ($settings['monthly_fee_amount'] ?? 0);
                if ($amount < 1) {
                    continue;
                }
                $dueDay = min(28, max(1, (int) ($settings['monthly_fee_due_day'] ?? 10)));
                $dueOn = $month->setDay($dueDay)->toDateString();
                $description = trim((string) ($settings['monthly_fee_description'] ?? 'Monthly school fee')) ?: 'Monthly school fee';
                $now = now();

                $tenant->table('school_students')->where('status', 'active')->orderBy('id')->chunkById(100, function ($students) use ($tenant, $month, $dueOn, $amount, $description, $now, &$created, &$skipped): void {
                    foreach ($students as $student) {
                        $reference = 'FEE-'.$month->format('Y-m').'-'.$student->id;
                        $inserted = DB::table('school_invoices')->insertOrIgnore([
                            'school_id' => $tenant->id(),
                            'branch_id' => $tenant->branchId(),
                            'reference' => $reference,
                            'student_id' => $student->id,
                            'description' => $description,
                            'amount' => $amount,
                            'due_on' => $dueOn,
                            'billing_month' => $month->format('Y-m'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        if ($inserted !== 1) {
                            $skipped++;
                            continue;
                        }
                        $created++;
                        $invoice = $tenant->table('school_invoices')->where('reference', $reference)->first(['id']);
                        DB::table('school_notification_events')->insertOrIgnore(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'module' => 'invoices', 'record_id' => $invoice->id, 'event_key' => 'invoices:'.$invoice->id.':monthly', 'created_at' => $now]);
                    }
                });
                DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => null, 'module' => 'invoices', 'record_id' => 0, 'action' => 'monthly_batch_created', 'changes' => json_encode(['billing_month' => $month->format('Y-m'), 'created' => $created, 'skipped' => $skipped]), 'created_at' => $now]);
            }
        }

        $this->info("Created {$created} monthly invoice(s); skipped {$skipped} existing invoice(s).");

        return self::SUCCESS;
    }
}
