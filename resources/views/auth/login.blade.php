@extends('layouts.portal')
@section('title', 'Sign in · School System')
@section('content')
<div class="split">
    <section class="story">
        <div class="eyebrow">Your school, together</div>
        <h1>A thoughtful start<br>to every school day.</h1>
        <p>One familiar place for your school community. Sign in with the account provided by your administrator.</p>
        <div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div>
    </section>
    <section class="form-panel" aria-labelledby="signin-title">
        <div class="eyebrow">Welcome back</div>
        <h2 id="signin-title">Sign in to your school</h2>
        <p>Enter your username and password to continue.</p>
        <form action="{{ route('login.store') }}" method="POST">
            @csrf
            <div class="field"><label for="username">Username</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="80" autofocus></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="255"></div>
            <button class="button full" type="submit">Sign in &rarr;</button>
        </form>
        <p class="helper" style="margin-top:24px">Need access or help signing in? Contact your school administrator.</p>
    </section>
</div>
@endsection