@extends('layouts.portal')
@section('title', 'Reset password · School System')
@section('content')
<div class="split">
    <section class="story"><div class="eyebrow">Secure account recovery</div><h1>Choose a new password.</h1><p>This private reset link expires after 60 minutes and works only once.</p><div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div></section>
    <section class="form-panel" aria-labelledby="reset-title"><div class="eyebrow">Account recovery</div><h2 id="reset-title">Reset your password</h2><p>Use at least 10 characters. You will be signed out of other devices.</p><form action="{{ route('password.reset.store', ['token' => $token]) }}" method="POST">@csrf<div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required autofocus></div><div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div><button class="button full" type="submit">Save new password</button></form></section>
</div>
@endsection
