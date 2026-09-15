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
<main class="platform-shell" id="platform-dashboard">
    <header class="platform-header">
        <div><p class="eyebrow">PLATFORM CONTROL CENTER</p><h1>Superadmin dashboard</h1><p>Welcome, {{ $user->name }}. Manage every school, account, and platform signal from one protected view.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
    </header>
    <section class="platform-grid" id="platform-summary" aria-live="polite">
        <article><strong>Loading…</strong><span>Schools</span></article><article><strong>Loading…</strong><span>Members</span></article><article><strong>Loading…</strong><span>Students</span></article><article><strong>Loading…</strong><span>Open invoices</span></article>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">SCHOOL REGISTRY</p><h2>Every school</h2></div><button class="platform-refresh" type="button">Refresh data</button></div>
        <div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Status</th><th>Members</th><th>Students</th><th>Staff</th><th>Open invoices</th><th>Control</th></tr></thead><tbody id="school-rows"><tr><td colspan="7">Loading school registry…</td></tr></tbody></table></div>
    </section>
    <section class="platform-panel"><div class="platform-panel-heading"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Recent platform activity</h2></div></div><div class="platform-table-wrap"><table><thead><tr><th>Time</th><th>School</th><th>Actor</th><th>Module</th><th>Action</th></tr></thead><tbody id="audit-rows"><tr><td colspan="5">Loading audit trail…</td></tr></tbody></table></div></section>
</main>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const summary = document.querySelector('#platform-summary');
    const schools = document.querySelector('#school-rows');
    const audit = document.querySelector('#audit-rows');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const load = async () => {
        const response = await fetch('/superadmin/data', {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) throw new Error('Unable to load platform data.');
        const data = await response.json();
        summary.innerHTML = [['schools','Schools'],['members','Members'],['students','Students'],['open_invoices','Open invoices']].map(([key,label]) => `<article><strong>${esc(data.summary[key])}</strong><span>${label}</span></article>`).join('');
        schools.innerHTML = data.schools.map(school => `<tr><td><strong>${esc(school.name)}</strong><small>${esc(school.slug)}</small></td><td><span class="platform-status ${esc(school.status)}">${esc(school.status)}</span></td><td>${esc(school.members)}</td><td>${esc(school.students)}</td><td>${esc(school.staff)}</td><td>${esc(school.open_invoices)}</td><td><button class="platform-action" data-id="${school.id}" data-status="${school.status === 'active' ? 'suspended' : 'active'}">${school.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`).join('') || '<tr><td colspan="7">No schools registered.</td></tr>';
        audit.innerHTML = data.audit.map(row => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.school_name)}</td><td>${esc(row.actor || 'System')}</td><td>${esc(row.module)}</td><td>${esc(row.action)}</td></tr>`).join('') || '<tr><td colspan="5">No audit events yet.</td></tr>';
        document.querySelectorAll('.platform-action').forEach(button => button.addEventListener('click', async () => {
            button.disabled = true;
            await fetch(`/superadmin/schools/${button.dataset.id}/status`, {method:'PUT', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({status:button.dataset.status})});
            await load();
        }));
    };
    document.querySelector('.platform-refresh').addEventListener('click', load);
    load().catch(error => { schools.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; });
})();
</script>
</body>
</html>
