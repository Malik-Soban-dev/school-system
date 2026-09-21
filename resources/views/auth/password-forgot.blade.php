@extends('layouts.portal')
@section('title', 'Recover your account · School System')
@section('content')
<div class="split">
    <section class="story"><div class="eyebrow">Account recovery</div><h1>Get back to your school workspace.</h1><p>Enter the email connected to your account. We will send a private, one-time reset link if the account is active.</p><div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div></section>
    <section class="form-panel" aria-labelledby="forgot-title"><div class="eyebrow">Secure recovery</div><h2 id="forgot-title">Forgot your password?</h2><p>For your privacy, the same message appears whether or not the email is registered.</p>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        <form action="{{ route('password.email') }}" method="POST">@csrf<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255" autofocus></div><button class="button full" type="submit">Send reset link</button></form>
        <p class="helper" style="margin-top:24px"><a href="{{ route('login') }}">Return to sign in</a></p>
    </section>
</div>
@endsection
