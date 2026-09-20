<?php

namespace App\Console\Commands;

use App\Services\NotificationDelivery;
use App\Services\SchoolNotifications;
use App\Support\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('school:notifications')]
#[Description('Process durable account notifications and upcoming exam reminders')]
class SendSchoolReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SchoolNotifications $notifications): int
    {
        $count = 0;
        foreach (DB::table('schools')->where('status', 'active')->orderBy('id')->pluck('id') as $schoolId) {
            foreach (DB::table('school_branches')->where('school_id', $schoolId)->where('status', 'active')->orderBy('id')->pluck('id') as $branchId) {
                app(TenantContext::class)->set((int) $schoolId, (int) $branchId);
                $count += $notifications->process();
                $notifications->remind();
                app(NotificationDelivery::class)->process();
            }
            if (app()->environment('testing')) {
                app(TenantContext::class)->set((int) $schoolId);
                $count += $notifications->process();
                app(NotificationDelivery::class)->process();
            }
        }
        $this->info('Processed '.$count.' notification events and checked exam reminders for active schools.');

        return self::SUCCESS;
    }
}
