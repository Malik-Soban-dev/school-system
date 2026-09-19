<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->hasRole('superadmin') && $user->mfa_enabled_at !== null && (int) $request->session()->get('mfa_verified_user_id') !== (int) $user->id) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => 'Multi-factor verification is required. Sign in again to continue.']);
        }

        return $next($request);
    }
}
