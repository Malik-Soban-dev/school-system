<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

#[Signature('school:backup {--path= : Destination for the encrypted backup file} {--disk= : Override the configured off-site backup disk}')]
#[Description('Create an encrypted SQLite/Turso database snapshot; retain the application key separately')]
class BackupSchoolDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path') ?: rtrim(config('backup.path', storage_path('app/private/backups')), '\\/').DIRECTORY_SEPARATOR.'school-'.now()->format('Ymd-His').'.enc';
        if (File::exists($path)) {
            $this->error('The backup destination already exists. Choose a new filename.');

            return self::FAILURE;
        }
        if (config('database.connections.'.config('database.default').'.driver') === 'turso') {
            $connection = config('database.connections.'.config('database.default'));
            $dump = Http::withToken($connection['access_token'])->connectTimeout(15)->timeout(120)->get(rtrim($connection['db_url'], '/').'/dump')->throw()->body();
            $snapshot = ['format' => 2, 'created_at' => now()->toIso8601String(), 'sql' => $dump];
        } else {
            $snapshot = DB::transaction(function (): array {
                $schema = DB::table('sqlite_master')->whereNotLike('name', 'sqlite_%')->whereNotNull('sql')->orderBy('type')->orderBy('name')->get(['type', 'name', 'sql']);
                $tables = [];
                foreach ($schema as $entry) {
                    if ($entry->type === 'table') {
                        $tables[$entry->name] = DB::table($entry->name)->get()->toArray();
                    }
                }

                return ['format' => 1, 'created_at' => now()->toIso8601String(), 'schema' => $schema->toArray(), 'tables' => $tables];
            });
        }
        $encrypted = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
        File::ensureDirectoryExists(dirname($path), 0700);
        if (file_put_contents($path, $encrypted, LOCK_EX) === false) {
            $this->error('The backup could not be written.');

            return self::FAILURE;
        }
        $diskName = (string) ($this->option('disk') ?: config('backup.disk', 'local'));
        if ($diskName !== 'local') {
            $remotePath = trim(config('backup.prefix', 'backups'), '/').'/'.basename($path);
            if (! Storage::disk($diskName)->put($remotePath, $encrypted, ['visibility' => 'private'])) {
                $this->error('The encrypted backup was written locally but could not be copied to the configured off-site disk.');

                return self::FAILURE;
            }
            $this->info('Encrypted backup copied to '.$diskName.':'.$remotePath);
        }
        $this->info('Encrypted backup created: '.$path);
        $this->line('Keep this file and the application encryption key in separate secure locations.');

        return self::SUCCESS;
    }
}
