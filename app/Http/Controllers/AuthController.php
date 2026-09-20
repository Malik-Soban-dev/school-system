<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $key = 'login:'.hash('sha256', $request->validated('username').'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many sign-in attempts. Please try again in a minute.']);
        }
        if (! Auth::attempt([...$request->safe()->only(['username', 'password']), 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($request->user()?->hasRole('superadmin') && $request->user()->mfa_enabled_at !== null) {
            $pendingUser = $request->user()->id;
            Auth::logout();
            $request->session()->regenerate();
            $request->session()->put('mfa_pending_user_id', $pendingUser);

            if ($request->expectsJson()) {
                return response()->json(['redirect' => route('mfa.challenge')]);
            }

            return redirect()->route('mfa.challenge');
        }
        $defaultDestination = $request->user()?->hasRole('superadmin') ? route('superadmin.dashboard') : route('dashboard');

        if ($request->expectsJson()) {
            return response()->json(['redirect' => $defaultDestination]);
        }

        return redirect()->intended($defaultDestination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill(['password' => $request->validated('password'), 'remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate();

        return back()->with('status', 'Your password has been updated.');
    }

    public function account(Request $request): View
    {
        $secret = null;
        $recoveryCodes = $request->session()->pull('mfa_recovery_codes', []);
        if ($pending = $request->session()->get('mfa_pending_secret')) {
            $secret = Crypt::decryptString($pending);
        }

        return view('auth.account', ['mfaPendingSecret' => $secret, 'mfaUri' => $secret ? Totp::uri($secret, $request->user()->username ?: $request->user()->email) : null, 'mfaRecoveryCodes' => $recoveryCodes]);
    }

    public function beginMfaEnrollment(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $request->validate(['current_password' => ['required', 'current_password']]);
        $secret = Totp::secret();
        $request->session()->put('mfa_pending_secret', Crypt::encryptString($secret));

        return redirect()->route('account')->with('status', 'MFA setup started. Add the secret to your authenticator, then confirm the current code below.');
    }

    public function confirmMfaEnrollment(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'code' => ['required', 'regex:/^\s*\d{6}\s*$/']]);
        $pending = $request->session()->get('mfa_pending_secret');
        abort_unless($pending, 422, 'Start MFA setup before confirming a code.');
        $secret = Crypt::decryptString($pending);
        abort_unless(Totp::verify($secret, $data['code']), 422, 'That authenticator code is not valid. Check the device time and try again.');
        $recoveryCodes = $this->newRecoveryCodes();
        DB::transaction(function () use ($request, $secret, $recoveryCodes): void {
            DB::table('users')->where('id', $request->user()->id)->update(['mfa_secret' => Crypt::encryptString($secret), 'mfa_recovery_codes' => json_encode(array_map(Hash::make(...), $recoveryCodes)), 'mfa_enabled_at' => now(), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $request->user()->id, 'action' => 'mfa_enabled', 'changes' => json_encode(['method' => 'totp']), 'created_at' => now()]);
        });
        $request->session()->forget('mfa_pending_secret');
        $request->session()->put('mfa_verified_user_id', $request->user()->id);
        $request->session()->put('mfa_recovery_codes', $recoveryCodes);

        return redirect()->route('account')->with('status', 'MFA is enabled for this Superadmin account.');
    }

    public function disableMfa(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'code' => ['required', 'string', 'max:100']]);
        $secret = Crypt::decryptString((string) $request->user()->mfa_secret);
        $valid = Totp::verify($secret, $data['code']) || $this->consumeRecoveryCode($request->user()->id, $data['code']);
        abort_unless($valid, 422, 'That authenticator or recovery code is not valid.');
        DB::transaction(function () use ($request): void {
            DB::table('users')->where('id', $request->user()->id)->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enabled_at' => null, 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $request->user()->id, 'action' => 'mfa_disabled', 'changes' => json_encode(['method' => 'totp']), 'created_at' => now()]);
        });
        $request->session()->forget(['mfa_pending_secret', 'mfa_verified_user_id']);

        return redirect()->route('account')->with('status', 'MFA has been disabled for this account.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('superadmin') && $request->user()->mfa_enabled_at !== null, 403);
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'code' => ['required', 'regex:/^\s*\d{6}\s*$/']]);
        abort_unless(Totp::verify(Crypt::decryptString((string) $request->user()->mfa_secret), $data['code']), 422, 'That authenticator code is not valid.');
        $recoveryCodes = $this->newRecoveryCodes();
        DB::transaction(function () use ($request, $recoveryCodes): void {
            DB::table('users')->where('id', $request->user()->id)->update(['mfa_recovery_codes' => json_encode(array_map(Hash::make(...), $recoveryCodes)), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $request->user()->id, 'action' => 'mfa_recovery_codes_regenerated', 'changes' => json_encode(['count' => count($recoveryCodes)]), 'created_at' => now()]);
        });
        $request->session()->put('mfa_recovery_codes', $recoveryCodes);

        return redirect()->route('account')->with('status', 'New recovery codes created. The previous codes no longer work.');
    }

    public function showMfaChallenge(Request $request): View
    {
        abort_unless($request->session()->has('mfa_pending_user_id'), 404);

        return view('auth.mfa-challenge');
    }

    public function verifyMfaChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $userId = (int) $request->session()->get('mfa_pending_user_id');
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();
        abort_unless($user?->hasRole('superadmin') && $user->mfa_enabled_at !== null, 404);
        $valid = Totp::verify(Crypt::decryptString((string) $user->mfa_secret), $data['code']) || $this->consumeRecoveryCode($user->id, $data['code']);
        abort_unless($valid, 422, 'That authenticator or recovery code is not valid.');
        Auth::login($user);
        $request->session()->forget('mfa_pending_user_id');
        $request->session()->regenerate();
        $request->session()->put('mfa_verified_user_id', $user->id);

        return redirect()->intended(route('superadmin.dashboard'));
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(fn (): string => strtoupper(bin2hex(random_bytes(5))), range(1, 8));
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        return DB::transaction(function () use ($userId, $code): bool {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['mfa_recovery_codes']);
            $hashes = json_decode((string) ($user?->mfa_recovery_codes ?? '[]'), true) ?: [];
            $normalized = strtoupper(trim($code));
            foreach ($hashes as $index => $hash) {
                if (Hash::check($normalized, $hash)) {
                    unset($hashes[$index]);
                    DB::table('users')->where('id', $userId)->update(['mfa_recovery_codes' => json_encode(array_values($hashes)), 'updated_at' => now()]);

                    return true;
                }
            }

            return false;
        });
    }

    public function showResetForm(string $token): View
    {
        $this->findReset($token);

        return view('auth.password-reset', compact('token'));
    }

    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', Password::min(10), 'max:72', 'confirmed']]);
        DB::transaction(function () use ($data, $token): void {
            $reset = $this->findReset($token, true);
            $user = DB::table('users')->where('email', $reset->email)->lockForUpdate()->first(['id']);
            abort_unless($user, 404);
            DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60), 'updated_at' => now()]);
            DB::table('password_reset_tokens')->where('email', $reset->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $memberships = DB::table('school_user')->where('user_id', $user->id)->pluck('school_id');
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $user->id, 'module' => 'platform', 'record_id' => $user->id, 'action' => 'password_reset_completed', 'changes' => json_encode(['method' => 'one_time_link']), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => null, 'entity_type' => 'user', 'entity_id' => $user->id, 'action' => 'password_reset_completed', 'changes' => json_encode(['school_ids' => $memberships->values()->all(), 'method' => 'one_time_link']), 'created_at' => now()]);
        });

        return redirect()->route('login')->with('status', 'Your password has been updated. You can sign in now.');
    }

    private function findReset(string $token, bool $lock = false): object
    {
        abort_unless(strlen($token) === 64, 404);
        $query = DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->where('created_at', '>', now()->subMinutes(60));
        if ($lock) {
            $query->lockForUpdate();
        }
        $reset = $query->first();
        abort_unless($reset, 404, 'This password reset link has expired or has already been used.');

        return $reset;
    }
}
