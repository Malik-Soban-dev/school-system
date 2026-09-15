<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Platform Superadmin · School System</title>
    @include('layouts.preferences-head')
    @vite('resources/js/portal.js')
</head>
<body>
<main class="platform-shell">
    <header class="platform-header">
        <div><p class="eyebrow">PLATFORM CONTROL CENTER</p><h1>Superadmin dashboard</h1><p>Welcome, {{ $user->name }}. This area is reserved for platform-level administration.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
    </header>
    <section class="platform-notice" role="status">
        <h2>Secure multi-school foundation in progress</h2>
        <p>School registry, tenant isolation, school switching, billing and cross-school records will be enabled here after the tenant foundation is completed. No school records are exposed through this dashboard until server-enforced isolation is ready.</p>
    </section>
    <section class="platform-grid">
        <article><strong>School registry</strong><span>Next foundation milestone</span></article>
        <article><strong>Data isolation</strong><span>Required before cross-school access</span></article>
        <article><strong>Audit controls</strong><span>Every platform action will be logged</span></article>
    </section>
</main>
</body>
</html>
