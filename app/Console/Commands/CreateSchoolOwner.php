<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSchoolOwner extends Command
{
    protected $signature = 'school:owner {username} {--name=} {--email=}';

    protected $description = 'Privately provision the first owner; read password from standard input';

    public function handle(): int
    {
        if (DB::table('school_settings')->where('key', 'owner_provisioned')->exists()) {
            $this->error('Owner setup is already complete. No account was changed.');

            return self::FAILURE;
        }
        $username = strtolower(trim($this->argument('username')));
        $password = $this->input->isInteractive() ? $this->secret('Owner password') : rtrim((string) fgets(STDIN), "\r\n");
        $validator = Validator::make(['username' => $username, 'password' => $password, 'email' => $this->option('email')], [
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,80}$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:10', 'max:72'],
            'email' => ['nullable', 'email', 'unique:users,email'],
        ]);
        if ($validator->fails()) {
            $this->error('Owner details are invalid. Use a unique username and a password of 10 to 72 characters.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($username, $password): void {
            DB::table('school_settings')->insert(['key' => 'owner_provisioned', 'value' => '1']);
            $user = new User;
            $user->forceFill([
                'name' => $this->option('name') ?: ucfirst($username),
                'username' => $username, 'email' => $this->option('email'),
                'password' => $password, 'roles' => ['owner'], 'is_active' => true,
            ])->save();
        });
        $this->info('Owner account created. Password was not logged.');

        return self::SUCCESS;
    }
}
