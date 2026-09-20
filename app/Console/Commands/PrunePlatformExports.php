<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('platform:prune-exports')]
#[Description('Remove expired private Superadmin export files and metadata')]
class PrunePlatformExports extends Command
{
    public function handle(): int
    {
        $pruned = 0;

        DB::table('platform_exports')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->select(['id', 'requested_by', 'type', 'file_path', 'row_count', 'expires_at'])
            ->chunkById(100, function ($exports) use (&$pruned): void {
                foreach ($exports as $export) {
                    $path = $this->safeExportPath($export->file_path);
                    if ($path && File::exists($path)) {
                        File::delete($path);
                    }

                    DB::transaction(function () use ($export): void {
                        DB::table('platform_exports')->where('id', $export->id)->delete();
                        DB::table('platform_audit')->insert([
                            'user_id' => $export->requested_by,
                            'entity_type' => 'export',
                            'entity_id' => $export->id,
                            'action' => 'export_pruned',
                            'changes' => json_encode([
                                'type' => $export->type,
                                'rows' => $export->row_count,
                                'expired_at' => $export->expires_at,
                            ]),
                            'created_at' => now(),
                        ]);
                    });

                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} expired platform export(s).");

        return self::SUCCESS;
    }

    private function safeExportPath(?string $relativePath): ?string
    {
        if (! $relativePath || ! preg_match('/^exports\/users-[0-9]+\.csv$/', $relativePath)) {
            return null;
        }

        return storage_path('app/private/'.$relativePath);
    }
}
