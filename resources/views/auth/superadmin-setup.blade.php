@extends('layouts.portal')
@section('title', 'Platform owner setup')
@section('content')
<div class="split">
    <section class="story">
        <div class="eyebrow">Platform owner</div>
        <h1>Create the<br>Superadmin account.</h1>
        <p>This private setup creates the separate owner account for the whole School System platform. It does not change any school administrator account.</p>
        <div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div>
    </section>
    <section class="form-panel" aria-labelledby="setup-title">
        <div class="eyebrow">One-time setup</div>
        <h2 id="setup-title">Platform owner credentials</h2>
        <p>Choose a new username and password for the separate Superadmin dashboard.</p>
        @if ($errors->any())
            <div class="notice errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form action="{{ route('superadmin.setup.store', ['token' => request('token')]) }}" method="POST">
            @csrf
            <div class="field"><label for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="255" autofocus></div>
            <div class="field"><label for="username">Superadmin username</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="80"></div>
            <div class="field"><label for="email">Email (optional)</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="255"></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" maxlength="72"></div>
            <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="10" maxlength="72"></div>
            <button class="button full" type="submit">Create Superadmin &rarr;</button>
        </form>
        <p class="helper" style="margin-top:24px">After creation, remove <code>SUPERADMIN_SETUP_TOKEN</code> from Render environment variables.</p>
    </section>
</div>
@endsection