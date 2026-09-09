@extends('layouts.portal')
@section('title', 'Join your school')
@section('content')
<meta name="referrer" content="no-referrer">
<div class="split"><section class="story"><div class="eyebrow">An invitation to belong</div><h1>Welcome,<br>{{ $invitation->name }}.</h1><p>Your school has invited you to join its workspace. Choose a username and password to activate your account.</p></section>
<section class="form-panel"><h2>Set up your account</h2><p>Invitation for {{ $invitation->email }}</p><form method="POST" action="{{ route('invitation.accept', $token) }}">@csrf
<div class="field"><label for="username">Choose a username</label><input id="username" name="username" required pattern="[a-z0-9._-]{3,80}" autocomplete="username" value="{{ old('username') }}"><p class="helper">3–80 lowercase letters, numbers, dots, underscores or hyphens.</p></div>
<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></div>
<div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></div>
<button class="button full" type="submit">Activate my account</button></form></section></div>
@endsection