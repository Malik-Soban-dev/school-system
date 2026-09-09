@extends('layouts.portal')
@section('title', 'School System · Welcome')
@section('content')
<div class="split">
    <section class="story"><div class="eyebrow">A place to grow</div><h1>Your school.<br>A little more<br>connected.</h1><p>Bringing people, learning and everyday school life together.</p><div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div></section>
    <section class="form-panel"><span class="eyebrow">School System is live.</span><h2 style="margin-top:20px">Welcome to your school portal.</h2><p>Sign in for your school records, attendance, class tools and fee information. Your workspace follows your role at school.</p><a class="button" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Open my workspace' : 'Sign in to continue' }} &rarr;</a><p class="helper" style="margin-top:24px">New here? Ask your school for a private invitation. Helpful page guides will show you where to start.</p></section>
</div>
@endsection
