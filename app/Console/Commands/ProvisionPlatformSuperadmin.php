<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('platform:superadmin {username} {--name=} {--email=} {--promote-existing}')]
#[Description('Create a platform Superadmin or explicitly promote an existing account')]
class ProvisionPlatformSuperadmin extends Command
{
    public function handle(): int
    {
        $username = strtolower(trim((string) $this->argument('username')));
        $existing = DB::table('users')->where('username', $username)->first(['id', 'name', 'email', 'roles', 'is_active']);
        if ($existing && ! $this->option('promote-existing')) {
            $this->error('That account already exists. Re-run with --promote-existing to explicitly elevate it.');

            return self::FAILURE;
        }

        $password = $this->input->isInteractive() ? $this->secret('Superadmin password') : rtrim((string) fgets(STDIN), "\r\n");
        $email = $this->option('email') !== null ? strtolower(trim((string) $this->option('email'))) : ($existing->email ?? null);
        $validator = Validator::make([
            'username' => $username,
            'name' => $this->option('name') ?: ($existing->name ?? ucfirst($username)),
            'email' => $email,
            'password' => $password,
        ], [
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,80}$/', Rule::unique('users', 'username')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($existing?->id)],
            'password' => ['required', 'string', 'min:10', 'max:72'],
        ]);
        if ($validator->fails()) {
            $this->error('Superadmin details are invalid. Use a unique username/email and a password of 10 to 72 characters.');

            return self::FAILURE;
        }

        $userId = DB::transaction(function () use ($existing, $username, $email, $password): int {
            $before = $existing ? DB::table('users')->where('id', $existing->id)->lockForUpdate()->first(['id', 'name', 'email', 'roles', 'is_active']) : null;
            if ($before) {
                DB::table('users')->where('id', $before->id)->update([
                    'name' => $this->option('name') ?: $before->name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'roles' => json_encode(['superadmin']),
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $userId = (int) $before->id;
            } else {
                $user = new User;
                $user->forceFill([
                    'name' => $this->option('name') ?: ucfirst($username),
                    'username' => $username,
                    'email' => $email,
                    'password' => $password,
                    'roles' => ['superadmin'],
                    'is_active' => true,
                ])->save();
                $userId = (int) $user->id;
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $userId)->delete();
            }
            DB::table('platform_audit')->insert([
                'user_id' => null,
                'entity_type' => 'user',
                'entity_id' => $userId,
                'action' => $before ? 'superadmin_promoted' : 'superadmin_provisioned',
                'changes' => json_encode([
                    'username' => $username,
                    'before_roles' => $before ? (json_decode((string) $before->roles, true) ?: []) : [],
                    'after_roles' => ['superadmin'],
                    'method' => 'operator_command',
                ]),
                'created_at' => now(),
            ]);

            return $userId;
        });

        $this->info(($existing ? 'Existing account promoted' : 'Superadmin account created').'. User ID: '.$userId.'. Password was not logged.');

        return self::SUCCESS;
    }
}
