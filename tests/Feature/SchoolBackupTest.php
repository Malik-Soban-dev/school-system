<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class SchoolBackupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_turso_backup_uses_native_dump_and_encrypts_it(): void
    {
        config(['database.default' => 'libsql', 'database.connections.libsql.db_url' => 'https://backup.example.test', 'database.connections.libsql.access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://backup.example.test/dump' => Http::response('CREATE TABLE demo (id INTEGER);', 200)]);
        $path = storage_path('framework/testing-backup-'.bin2hex(random_bytes(8)).'.enc');
        try {
            $this->artisan('school:backup', ['--path' => $path])->assertSuccessful();
            $snapshot = json_decode(Crypt::decryptString(File::get($path)), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(2, $snapshot['format']);
            $this->assertSame('CREATE TABLE demo (id INTEGER);', $snapshot['sql']);
            $this->artisan('school:verify-backup', ['path' => $path])->assertSuccessful();
            Http::assertSent(fn ($request): bool => $request->url() === 'https://backup.example.test/dump' && $request->hasHeader('Authorization', 'Bearer test-token'));
        } finally {
            File::delete($path);
        }
    }

    public function test_encrypted_backup_can_restore_records_into_an_isolated_database(): void
    {
        $user = User::factory()->create(['name' => 'Backup Test Person', 'roles' => ['owner'], 'is_active' => true]);
        $path = storage_path('framework/testing-backup-'.bin2hex(random_bytes(8)).'.enc');
        try {
            $this->artisan('school:backup', ['--path' => $path])->assertSuccessful();
            $encrypted = File::get($path);
            $this->assertStringNotContainsString('Backup Test Person', $encrypted);
            $snapshot = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            $restored = new PDO('sqlite::memory:');
            foreach ($snapshot['schema'] as $entry) {
                if ($entry['type'] === 'table') {
                    $restored->exec($entry['sql']);
                }
            }
            foreach ($snapshot['tables'] as $table => $rows) {
                foreach ($rows as $row) {
                    $columns = implode(', ', array_map(fn (string $key): string => '"'.str_replace('"', '""', $key).'"', array_keys($row)));
                    $statement = $restored->prepare('INSERT INTO "'.str_replace('"', '""', $table).'" ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
                    $statement->execute(array_values($row));
                }
            }
            $restoredUser = $restored->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($user->name, $restoredUser['name']);
            $this->assertSame($user->password, $restoredUser['password']);
            $this->assertSame(['owner'], json_decode($restoredUser['roles'], true));
            $this->artisan('school:verify-backup', ['path' => $path])->assertSuccessful();
            $this->artisan('school:backup', ['--path' => $path])->assertFailed();
            File::put($path, 'tampered-backup');
            $this->artisan('school:verify-backup', ['path' => $path])->assertFailed();
        } finally {
            File::delete($path);
        }
    }
}
