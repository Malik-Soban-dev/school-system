<?php

namespace App\Providers;

use App\Support\PlatformSchedulerRuns;
use App\Support\TenantContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(PlatformSchedulerRuns::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            app(PlatformSchedulerRuns::class)->start($event->command);
        });
        $this->app['events']->listen(CommandFinished::class, function (CommandFinished $event): void {
            app(PlatformSchedulerRuns::class)->finish($event->command, $event->exitCode);
        });
    }
}
