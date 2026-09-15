@extends('layouts.portal')
@section('title', 'Join your school')
@section('content')
<meta name="referrer" content="no-referrer">
<div class="split"><section class="story"><div class="eyebrow"><span data-i18n="An invitation to belong">An invitation to belong</span></div><h1><span data-i18n="Welcome,">Welcome,</span><br>{{ $invitation->name }}.</h1><p><span data-i18n="Your school has invited you to join its workspace. Choose a username and password to activate your account.">Your school has invited you to join its workspace. Choose a username and password to activate your account.</span></p></section>
<section class="form-panel"><h2><span data-i18n="Set up your account">Set up your account</span></h2><p>Invitation for {{ $invitation->email }}</p><form method="POST" action="{{ route('invitation.accept', $token) }}">@csrf
<div class="field"><label for="username"><span data-i18n="Choose a username">Choose a username</span></label><input id="username" name="username" required pattern="[a-z0-9._-]{3,80}" autocomplete="username" value="{{ old('username') }}"><p class="helper"><span data-i18n="3–80 lowercase letters, numbers, dots, underscores or hyphens.">3–80 lowercase letters, numbers, dots, underscores or hyphens.</span></p></div>
<div class="field"><label for="password"><span data-i18n="Password">Password</span></label><input id="password" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></div>
<div class="field"><label for="password_confirmation"><span data-i18n="Confirm password">Confirm password</span></label><input id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></div>
<button class="button full" type="submit"><span data-i18n="Activate my account">Activate my account</span></button></form></section></div>
@endsection