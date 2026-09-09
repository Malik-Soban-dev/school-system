<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

#[Signature('school:verify-backup {path : Encrypted backup to restore into temporary memory}')]
#[Description('Verify an encrypted backup by restoring it into an isolated in-memory database')]
class VerifySchoolBackup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $snapshot = json_decode(Crypt::decryptString(File::get($this->argument('path'))), true, flags: JSON_THROW_ON_ERROR);
            $database = new PDO('sqlite::memory:');
            $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (($snapshot['format'] ?? null) === 2) {
                $database->exec($snapshot['sql']);
            } elseif (($snapshot['format'] ?? null) === 1) {
                foreach ($snapshot['schema'] as $entry) {
                    if ($entry['type'] === 'table') {
                        $database->exec($entry['sql']);
                    }
                }
                foreach ($snapshot['tables'] as $table => $rows) {
                    foreach ($rows as $row) {
                        $columns = implode(', ', array_map($this->quoteIdentifier(...), array_keys($row)));
                        $statement = $database->prepare('INSERT INTO '.$this->quoteIdentifier($table).' ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
                        $statement->execute(array_values($row));
                    }
                }
                foreach ($snapshot['schema'] as $entry) {
                    if ($entry['type'] !== 'table') {
                        $database->exec($entry['sql']);
                    }
                }
            } else {
                $this->error('Unsupported backup format.');

                return self::FAILURE;
            }
            if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $database->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                $this->error('Restored backup failed database integrity checks.');

                return self::FAILURE;
            }
            $count = $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
            $this->info('Backup restored successfully in memory. '.$count.' tables passed integrity checks.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Backup verification failed. Check the file, encryption key and database compatibility.');

            return self::FAILURE;
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
