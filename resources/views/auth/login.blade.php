@extends('layouts.portal')
@section('title', 'Sign in · School System')
@section('content')
<div class="split">
    <section class="story">
        <div class="eyebrow"><span data-i18n="Your school, together">Your school, together</span></div>
        <h1><span data-i18n="A thoughtful start">A thoughtful start</span><br><span data-i18n="to every school day.">to every school day.</span></h1>
        <p><span data-i18n="One familiar place for your school community. Sign in with the account provided by your administrator.">One familiar place for your school community. Sign in with the account provided by your administrator.</span></p>
        <div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div>
    </section>
    <section class="form-panel" aria-labelledby="signin-title">
        <div class="eyebrow"><span data-i18n="Welcome back">Welcome back</span></div>
        <h2 id="signin-title"><span data-i18n="Sign in to your school">Sign in to your school</span></h2>
        <p><span data-i18n="Enter your username and password to continue.">Enter your username and password to continue.</span></p>
        <form action="{{ route('login.store') }}" method="POST" data-login-form>
            @csrf
            <div class="field"><label for="username"><span data-i18n="Username">Username</span></label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="80" autofocus></div>
            <div class="field"><label for="password"><span data-i18n="Password">Password</span></label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="255"></div>
            <button class="button full" type="submit"><span data-i18n="Sign in →">Sign in &rarr;</span></button>
        <div id="login-progress" class="login-progress" role="status" hidden><span data-i18n="Opening your school workspace…">Opening your school workspace…</span></div><div id="login-error" class="notice errors" role="alert" tabindex="-1" hidden></div></form>
        <p class="helper" style="margin-top:24px"><span data-i18n="Need access or help signing in? Contact your school administrator.">Need access or help signing in? Contact your school administrator.</span></p>
    </section>
</div>
@endsection
