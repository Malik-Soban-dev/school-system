@extends('layouts.portal')
@section('title', 'My account · School System')
@section('content')
<div class="page-heading"><div><div class="eyebrow">Account settings</div><h1>Your space. Your security.</h1><p>Signed in as {{ auth()->user()->username }}.</p></div></div>
<section class="card account-form">
    <h2>Change your password</h2>
    <p style="margin-bottom:24px">Choose a password with at least 10 characters.</p>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf @method('PUT')
        <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
        <div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
        <button class="button" type="submit">Update password</button>
    </form>
</section>
@endsection