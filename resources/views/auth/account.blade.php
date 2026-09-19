@extends('layouts.portal')
@section('title', 'My account · School System')
@section('content')
<div class="page-heading"><div><div class="eyebrow"><span data-i18n="Account settings">Account settings</span></div><h1><span data-i18n="Your space. Your security.">Your space. Your security.</span></h1><p>Signed in as {{ auth()->user()->username }}.</p></div></div>
<section class="card account-form">
    <h2><span data-i18n="Change your password">Change your password</span></h2>
    <p style="margin-bottom:24px"><span data-i18n="Choose a password with at least 10 characters.">Choose a password with at least 10 characters.</span></p>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf @method('PUT')
        <div class="field"><label for="current_password"><span data-i18n="Current password">Current password</span></label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="password"><span data-i18n="New password">New password</span></label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
        <div class="field"><label for="password_confirmation"><span data-i18n="Confirm new password">Confirm new password</span></label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
        <button class="button" type="submit"><span data-i18n="Update password">Update password</span></button>
    </form>
</section>
@if(auth()->user()->hasRole('superadmin'))
<section class="card account-form" style="margin-top:20px">
    <h2>Multi-factor authentication</h2>
    @if(auth()->user()->mfa_enabled_at)
        <p>MFA is enabled for this Superadmin account. An authenticator or unused recovery code is required at every new sign-in.</p>
        @if($mfaRecoveryCodes)
            <div class="notice"><strong>Save these recovery codes now.</strong><br>Each code works once. They are shown only after setup or regeneration.<br><code>{{ implode(' · ', $mfaRecoveryCodes) }}</code></div>
        @endif
        <form method="POST" action="{{ route('mfa.recovery-codes') }}" style="margin-bottom:24px">
            @csrf
            <div class="field"><label for="regenerate_current_password">Current password</label><input id="regenerate_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label for="regenerate_code">Current authenticator code</label><input id="regenerate_code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" required></div>
            <button class="button secondary" type="submit">Regenerate recovery codes</button>
        </form>
        <form method="POST" action="{{ route('mfa.disable') }}">
            @csrf @method('DELETE')
            <div class="field"><label for="disable_current_password">Current password</label><input id="disable_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label for="disable_code">Authenticator or recovery code</label><input id="disable_code" name="code" autocomplete="one-time-code" required></div>
            <button class="button secondary" type="submit">Disable MFA</button>
        </form>
    @elseif($mfaPendingSecret)
        <p>Add this secret to an authenticator app. The setup URI can be entered manually if the app does not support scanning.</p>
        <p><strong>Secret:</strong> <code>{{ $mfaPendingSecret }}</code><br><small>{{ $mfaUri }}</small></p>
        <form method="POST" action="{{ route('mfa.confirm') }}">
            @csrf
            <div class="field"><label for="confirm_current_password">Current password</label><input id="confirm_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label for="confirm_code">Authenticator code</label><input id="confirm_code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" required></div>
            <button class="button" type="submit">Confirm and enable MFA</button>
        </form>
    @else
        <p>Protect platform-wide school and account administration with a time-based authenticator code.</p>
        <form method="POST" action="{{ route('mfa.setup') }}">
            @csrf
            <div class="field"><label for="setup_current_password">Current password</label><input id="setup_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <button class="button" type="submit">Start MFA setup</button>
        </form>
    @endif
</section>
@endif
@endsection
