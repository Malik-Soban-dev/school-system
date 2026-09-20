<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperadminSetupController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorizeSetup($request);

        return view('auth.superadmin-setup');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSetup($request);

        $data = $request->validate([
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,80}$/', Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:10', 'max:72', 'confirmed'],
        ]);

        $userId = DB::transaction(function () use ($data): int {
            $hasSuperadmin = User::query()->where('is_active', true)->get(['roles', 'is_active'])->contains(fn (User $user): bool => $user->hasRole('superadmin'));
            abort_if($hasSuperadmin, 404);

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'roles' => ['superadmin'],
                'is_active' => true,
                'remember_token' => Str::random(60),
            ])->save();

            if (Schema::hasTable('platform_audit')) {
                DB::table('platform_audit')->insert([
                    'user_id' => null,
                    'entity_type' => 'user',
                    'entity_id' => $user->id,
                    'action' => 'superadmin_provisioned',
                    'changes' => json_encode(['username' => $user->username, 'after_roles' => ['superadmin'], 'method' => 'one_time_web_setup']),
                    'created_at' => now(),
                ]);
            }

            return (int) $user->id;
        });

        return redirect()->route('login')->with('status', 'Superadmin account created. Sign in with your new platform-owner credentials.');
    }

    private function authorizeSetup(Request $request): void
    {
        $configuredToken = (string) config('services.superadmin_setup.token', '');
        $providedToken = (string) $request->query('token', $request->input('token', ''));
        abort_unless(strlen($configuredToken) >= 32 && hash_equals($configuredToken, $providedToken), 404);

        $hasSuperadmin = User::query()->where('is_active', true)->get(['roles', 'is_active'])->contains(fn (User $user): bool => $user->hasRole('superadmin'));
        abort_if($hasSuperadmin, 404);
    }
}
