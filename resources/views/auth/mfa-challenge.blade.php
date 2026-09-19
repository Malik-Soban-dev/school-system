@extends('layouts.portal')
@section('title', 'MFA verification · School System')
@section('content')
<div class="split">
    <section class="story"><div class="eyebrow">Platform security</div><h1>One more step.</h1><p>Your Superadmin account is protected by multi-factor authentication. Enter the current code from your authenticator.</p><div class="art" aria-hidden="true"><span class="sun"></span><span class="one"></span><span class="two"></span><span class="three"></span></div></section>
    <section class="form-panel" aria-labelledby="mfa-title"><div class="eyebrow">Authenticator verification</div><h2 id="mfa-title">Verify your identity</h2><form action="{{ route('mfa.challenge.verify') }}" method="POST">@csrf<div class="field"><label for="code">Authenticator or recovery code</label><input id="code" name="code" autocomplete="one-time-code" required autofocus></div><button class="button full" type="submit">Continue to Superadmin</button></form><p class="helper" style="margin-top:24px">Use one of the single-use recovery codes if your authenticator device is unavailable.</p></section>
</div>
@endsection
