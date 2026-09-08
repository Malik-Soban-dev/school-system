@extends('layouts.portal')
@section('title', 'Overview · School System')
@section('content')
<div class="page-heading">
    <div><div class="eyebrow">Your school workspace</div><h1>Welcome, {{ auth()->user()->name }}.</h1><p>A clear starting point for your school.</p></div>
    <div>@foreach(auth()->user()->roles ?? [] as $role)<span class="badge">{{ ucfirst(str_replace('_', ' ', $role)) }}</span>@endforeach</div>
</div>
<div class="banner"><div><h2>Your account is ready.</h2><p>You can sign in, sign out and manage your password. School tools will appear here as they become available.</p></div><a class="button secondary" href="{{ route('account') }}">My account</a></div>
<div class="grid">
    <section class="card"><div class="eyebrow">01 / Access</div><div class="number">Connected</div><h2>Your school account</h2><p>Your username is {{ auth()->user()->username }}. Access is assigned by your school.</p></section>
    <section class="card"><div class="eyebrow">02 / Community</div><div class="number">Coming next</div><h2>People &amp; invitations</h2><p>Invite staff and connect students with their guardians.</p></section>
    <section class="card"><div class="eyebrow">03 / Learning</div><div class="number">On the roadmap</div><h2>Classes &amp; attendance</h2><p>Organize academic years, class groups and daily attendance.</p></section>
</div>
@endsection