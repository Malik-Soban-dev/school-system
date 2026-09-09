<?php

namespace App\Console\Commands;

use App\Services\NotificationDelivery;
use App\Services\SchoolNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('school:notifications')]
#[Description('Process durable account notifications and upcoming exam reminders')]
class SendSchoolReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SchoolNotifications $notifications): int
    {
        $count = $notifications->process();
        $notifications->remind();
        app(NotificationDelivery::class)->process();
        $this->info('Processed '.$count.' notification events and checked exam reminders.');

        return self::SUCCESS;
    }
}
