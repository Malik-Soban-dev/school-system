<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('platform:mark-overdue-invoices {--until= : Mark issued invoices due before this date as overdue}')]
#[Description('Mark past-due platform invoices overdue with an audit trail')]
class MarkPlatformInvoicesOverdue extends Command
{
    public function handle(): int
    {
        $until = $this->option('until')
            ? CarbonImmutable::parse($this->option('until'))->toDateString()
            : CarbonImmutable::today()->toDateString();
        $marked = 0;
        $skipped = 0;

        DB::table('platform_billing_invoices')
            ->where('status', 'issued')
            ->whereDate('due_on', '<', $until)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $invoiceId) use ($until, &$marked, &$skipped): void {
                $result = DB::transaction(function () use ($invoiceId, $until): string {
                    $invoice = DB::table('platform_billing_invoices')->where('id', $invoiceId)->lockForUpdate()->first(['id', 'school_id', 'status', 'due_on']);
                    if (! $invoice || $invoice->status !== 'issued' || (string) $invoice->due_on >= $until) {
                        return 'skipped';
                    }

                    DB::table('platform_billing_invoices')->where('id', $invoiceId)->update(['status' => 'overdue', 'updated_at' => now()]);
                    DB::table('platform_audit')->insert(['user_id' => null, 'entity_type' => 'platform_invoice', 'entity_id' => $invoiceId, 'action' => 'invoice_marked_overdue', 'changes' => json_encode(['school_id' => $invoice->school_id, 'due_on' => $invoice->due_on, 'as_of' => $until, 'before_status' => 'issued', 'after_status' => 'overdue']), 'created_at' => now()]);

                    return 'marked';
                });

                $result === 'marked' ? $marked++ : $skipped++;
            });

        $this->info("Marked {$marked} platform invoice(s) overdue; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
