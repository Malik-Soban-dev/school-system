<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PlatformSchedulerRuns
{
    /** @var list<string> */
    private array $trackedCommands = [
        'school:notifications',
        'platform:generate-invoices',
        'platform:mark-overdue-invoices',
        'platform:prune-exports',
    ];

    /** @var array<string, int> */
    private array $active = [];

    public function tracks(string $command): bool
    {
        return in_array($command, $this->trackedCommands, true);
    }

    public function start(string $command): void
    {
        if (! $this->tracks($command) || ! Schema::hasTable('platform_scheduler_runs')) {
            return;
        }

        try {
            DB::table('platform_scheduler_runs')->whereNotNull('finished_at')->where('finished_at', '<', now()->subDays(90))->delete();
            $this->active[$command] = DB::table('platform_scheduler_runs')->insertGetId(['command' => $command, 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            // Health tracking must never prevent scheduled work from running.
        }
    }

    public function finish(string $command, int $exitCode): void
    {
        $runId = $this->active[$command] ?? null;
        unset($this->active[$command]);
        if (! $runId) {
            return;
        }

        try {
            DB::table('platform_scheduler_runs')->where('id', $runId)->update(['status' => $exitCode === 0 ? 'success' : 'failed', 'exit_code' => $exitCode, 'error_type' => $exitCode === 0 ? null : 'command_exit_'.$exitCode, 'finished_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            // Do not turn bookkeeping failures into application failures.
        }
    }
}
