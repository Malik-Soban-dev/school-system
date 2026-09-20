<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BuildPlatformUserExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $export = DB::table('platform_exports')->where('id', $this->exportId)->where('type', 'users')->first(['id', 'requested_by']);
        if (! $export) {
            return;
        }
        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'processing', 'updated_at' => now()]);
        $relativePath = 'exports/users-'.$this->exportId.'.csv';
        $path = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['user_id', 'name', 'username', 'email', 'account_status', 'global_roles', 'school', 'school_status', 'school_membership_status', 'branch', 'branch_status', 'branch_roles', 'branch_access_status']);
        $rows = 0;
        DB::table('users as u')->select(['u.id', 'u.name', 'u.username', 'u.email', 'u.is_active', 'u.roles'])->orderBy('u.id')->chunkById(500, function ($users) use ($handle, &$rows): void {
            $userIds = $users->pluck('id');
            $access = DB::table('school_user_branches as a')->join('schools as s', 's.id', '=', 'a.school_id')->join('school_branches as b', 'b.id', '=', 'a.branch_id')->leftJoin('school_user as membership', function ($join): void {
                $join->on('membership.user_id', '=', 'a.user_id')->on('membership.school_id', '=', 'a.school_id');
            })->whereIn('a.user_id', $userIds)->orderBy('s.name')->orderBy('b.name')->get(['a.user_id', 's.name as school_name', 's.status as school_status', 'membership.status as school_membership_status', 'b.name as branch_name', 'b.status as branch_status', 'a.roles', 'a.status'])->groupBy('user_id');
            foreach ($users as $user) {
                $grants = $access->get($user->id, collect());
                if ($grants->isEmpty()) {
                    fputcsv($handle, [$user->id, $user->name, $user->username, $user->email, $user->is_active ? 'active' : 'suspended', implode('|', json_decode((string) $user->roles, true) ?: []), '', '', '', '', '', '', '']);
                    $rows++;

                    continue;
                }
                foreach ($grants as $grant) {
                    fputcsv($handle, [$user->id, $user->name, $user->username, $user->email, $user->is_active ? 'active' : 'suspended', implode('|', json_decode((string) $user->roles, true) ?: []), $grant->school_name, $grant->school_status, $grant->school_membership_status, $grant->branch_name, $grant->branch_status, implode('|', json_decode((string) $grant->roles, true) ?: []), $grant->status]);
                    $rows++;
                }
            }
        }, 'u.id', 'id');
        fclose($handle);
        $expiresAt = now()->addDays(7);
        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'completed', 'file_path' => $relativePath, 'row_count' => $rows, 'expires_at' => $expiresAt, 'updated_at' => now()]);
        DB::table('platform_audit')->insert(['user_id' => $export->requested_by, 'entity_type' => 'export', 'entity_id' => $this->exportId, 'action' => 'user_export_completed', 'changes' => json_encode(['rows' => $rows, 'expires_at' => $expiresAt->toIso8601String()]), 'created_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        $export = DB::table('platform_exports')->where('id', $this->exportId)->first(['id', 'requested_by', 'type']);
        File::delete(storage_path('app/private/exports/users-'.$this->exportId.'.csv'));
        DB::table('platform_exports')->where('id', $this->exportId)->update(['status' => 'failed', 'file_path' => null, 'expires_at' => null, 'error' => substr($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
        if ($export) {
            DB::table('platform_audit')->insert(['user_id' => $export->requested_by, 'entity_type' => 'export', 'entity_id' => $this->exportId, 'action' => 'user_export_failed', 'changes' => json_encode(['type' => $export->type]), 'created_at' => now()]);
        }
    }
}
