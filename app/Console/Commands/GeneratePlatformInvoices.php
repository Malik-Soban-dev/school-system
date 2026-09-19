<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('platform:generate-invoices {--until= : Generate invoices due on or before this date}')]
#[Description('Generate one repeat-safe renewal invoice for each due paid subscription')]
class GeneratePlatformInvoices extends Command
{
    public function handle(): int
    {
        $until = $this->option('until')
            ? CarbonImmutable::parse($this->option('until'))->endOfDay()
            : CarbonImmutable::now();
        $created = 0;
        $skipped = 0;

        DB::table('school_subscriptions as subscription')
            ->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')
            ->where('subscription.status', 'active')
            ->whereNotNull('subscription.renews_at')
            ->where('subscription.renews_at', '<=', $until)
            ->orderBy('subscription.id')
            ->select(['subscription.id', 'subscription.school_id', 'subscription.renews_at', 'plan.monthly_price_cents'])
            ->get()
            ->each(function (object $candidate) use (&$created, &$skipped): void {
                $result = DB::transaction(function () use ($candidate): string {
                    $subscription = DB::table('school_subscriptions as subscription')
                        ->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')
                        ->where('subscription.id', $candidate->id)
                        ->lockForUpdate()
                        ->first(['subscription.id', 'subscription.school_id', 'subscription.status', 'subscription.renews_at', 'plan.monthly_price_cents']);

                    if (! $subscription || $subscription->status !== 'active' || ! $subscription->renews_at || (int) $subscription->monthly_price_cents < 1) {
                        return 'skipped';
                    }

                    $periodEnd = CarbonImmutable::parse($subscription->renews_at)->startOfDay();
                    $billingKey = 'renewal:'.$subscription->id.':'.$periodEnd->toDateString();
                    if (DB::table('platform_billing_invoices')->where('billing_key', $billingKey)->exists()) {
                        return 'skipped';
                    }

                    $periodStart = $periodEnd->subMonth();
                    $invoiceId = DB::table('platform_billing_invoices')->insertGetId([
                        'school_id' => $subscription->school_id,
                        'subscription_id' => $subscription->id,
                        'billing_key' => $billingKey,
                        'created_by' => null,
                        'invoice_number' => 'PLAT-'.str_pad((string) $subscription->school_id, 6, '0', STR_PAD_LEFT).'-'.$periodEnd->format('Ym').'-'.Str::upper(Str::random(6)),
                        'amount_cents' => $subscription->monthly_price_cents,
                        'currency' => 'USD',
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'due_on' => $periodEnd->toDateString(),
                        'status' => 'issued',
                        'notes' => 'Automatic subscription renewal invoice.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('platform_audit')->insert([
                        'user_id' => null,
                        'entity_type' => 'platform_invoice',
                        'entity_id' => $invoiceId,
                        'action' => 'invoice_generated',
                        'changes' => json_encode(['school_id' => $subscription->school_id, 'subscription_id' => $subscription->id, 'billing_key' => $billingKey]),
                        'created_at' => now(),
                    ]);

                    return 'created';
                });

                $result === 'created' ? $created++ : $skipped++;
            });

        $this->info("Generated {$created} platform renewal invoice(s); skipped {$skipped}.");

        return self::SUCCESS;
    }
}
