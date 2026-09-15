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
@endsection