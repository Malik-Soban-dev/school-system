<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('dashboard')]);
        }

        return redirect()->intended(route('dashboard'));
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
