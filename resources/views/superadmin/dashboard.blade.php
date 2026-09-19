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
        <article><strong>Loading…</strong><span>Schools</span></article><article><strong>Loading…</strong><span>Branches</span></article><article><strong>Loading…</strong><span>Members</span></article><article><strong>Loading…</strong><span>Students</span></article><article><strong>Loading…</strong><span>Open invoices</span></article>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">SCHOOL REGISTRY</p><h2>Every school</h2></div><div><button class="platform-refresh" type="button">Refresh data</button></div></div>
        <form id="create-school-form"><input name="name" required maxlength="150" placeholder="New school name" aria-label="New school name"><input name="slug" required maxlength="80" pattern="[A-Za-z0-9_-]+" placeholder="Slug" aria-label="New school slug"><button type="submit">Onboard school</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Status</th><th>Branches</th><th>Members</th><th>Students</th><th>Staff</th><th>Open invoices</th><th>Control</th></tr></thead><tbody id="school-rows"><tr><td colspan="8">Loading school registry…</td></tr></tbody></table></div>
    </section>
    <section class="platform-panel" id="school-detail" hidden>
        <div class="platform-panel-heading"><div><p class="eyebrow">SCHOOL DETAIL</p><h2 id="school-detail-title">Selected school</h2></div><button class="platform-refresh" id="school-detail-close" type="button">Close</button></div>
        <div id="school-detail-content">Select a school to inspect its members and activity.</div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">PLATFORM ACCOUNTS</p><h2>Every user</h2></div></div>
        <form id="user-search-form"><input id="user-search" name="search" maxlength="100" placeholder="Search name, email or username" aria-label="Search platform users"><button type="submit">Search</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>User</th><th>Status</th><th>School / branch access</th><th>Control</th></tr></thead><tbody id="user-rows"><tr><td colspan="4">Loading platform users…</td></tr></tbody></table></div>
    </section>
    <section class="platform-panel"><div class="platform-panel-heading"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Recent platform activity</h2></div></div><div class="platform-table-wrap"><table><thead><tr><th>Time</th><th>School</th><th>Actor</th><th>Module</th><th>Action</th></tr></thead><tbody id="audit-rows"><tr><td colspan="5">Loading audit trail…</td></tr></tbody></table></div></section>
</main>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const summary = document.querySelector('#platform-summary');
    const schools = document.querySelector('#school-rows');
    const audit = document.querySelector('#audit-rows');
    const detail = document.querySelector('#school-detail');
    const detailTitle = document.querySelector('#school-detail-title');
    const detailContent = document.querySelector('#school-detail-content');
    const userRows = document.querySelector('#user-rows');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const request = async (url, options = {}) => {
        const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json', ...options.headers}, ...options});
        if (!response.ok) {
            throw new Error((await response.json().catch(() => ({}))).message || 'The platform request failed.');
        }
        return response.status === 204 ? null : response.json();
    };
    const showSchoolDetail = async schoolId => {
        detail.hidden = false;
        detailContent.innerHTML = 'Loading school detail…';
        const data = await request(`/superadmin/schools/${schoolId}`);
        detailTitle.textContent = data.school.name;
        const counts = Object.entries(data.counts).map(([key, value]) => `<span><strong>${esc(value)}</strong> ${esc(key)}</span>`).join(' · ');
        const branchOptions = data.branches.map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)} (${esc(branch.code)})</option>`).join('');
        const branchRows = data.branches.map(branch => `<tr><td>${esc(branch.name)}${branch.is_default ? ' <small>Default</small>' : ''}</td><td>${esc(branch.code)}</td><td><span class="platform-status ${esc(branch.status)}">${esc(branch.status)}</span></td><td>${esc(branch.students)} students · ${esc(branch.staff)} staff · ${esc(branch.classes)} classes</td><td><button class="branch-status" data-branch="${esc(branch.id)}" data-status="${branch.status === 'active' ? 'suspended' : 'active'}">${branch.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`).join('') || '<tr><td colspan="5">No branches registered.</td></tr>';
        const members = data.members.map(member => `<tr><td>${esc(member.name)}</td><td>${esc(member.email)}</td><td>${esc(member.is_active ? 'Active' : 'Inactive')}</td><td><form class="branch-access-form" data-school="${esc(data.school.id)}" data-user="${esc(member.id)}"><select name="branch_id" aria-label="Branch for ${esc(member.name)}">${branchOptions}</select><select name="role" aria-label="Role for ${esc(member.name)}"><option value="admin">Admin</option><option value="owner">Owner</option><option value="teacher">Teacher</option><option value="accountant">Accountant</option></select><button type="submit">Grant access</button></form></td></tr>`).join('') || '<tr><td colspan="4">No active members.</td></tr>';
        const activity = data.audit.map(row => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.actor || 'System')}</td><td>${esc(row.module)}</td><td>${esc(row.action)}</td></tr>`).join('') || '<tr><td colspan="4">No school activity yet.</td></tr>';
        detailContent.innerHTML = `<p>${counts}</p><h3>Branches</h3><form id="create-branch-form"><input name="name" required maxlength="120" placeholder="Branch name" aria-label="Branch name"><input name="code" required maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="Code" aria-label="Branch code"><button type="submit">Create branch</button></form><div class="platform-table-wrap"><table><thead><tr><th>Name</th><th>Code</th><th>Status</th><th>Records</th><th>Control</th></tr></thead><tbody>${branchRows}</tbody></table></div><h3>Active members</h3><div class="platform-table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Branch access</th></tr></thead><tbody>${members}</tbody></table></div><h3>Current branch grants</h3><div class="platform-table-wrap"><table><thead><tr><th>Member</th><th>Branch</th><th>Roles</th><th>Status</th><th>Control</th></tr></thead><tbody>${data.access.map(access => { const member = data.members.find(item => item.id === access.user_id); return `<tr><td>${esc(member?.name || 'Unknown user')}</td><td>${esc(access.branch_name)}</td><td>${esc(JSON.parse(access.roles || '[]').join(', '))}</td><td>${esc(access.status)}</td><td><button class="access-status" data-user="${esc(access.user_id)}" data-branch="${esc(access.branch_id)}" data-status="${access.status === 'active' ? 'suspended' : 'active'}">${access.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`; }).join('') || '<tr><td colspan="5">No branch grants registered.</td></tr>'}</tbody></table></div><h3>Recent activity</h3><div class="platform-table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Module</th><th>Action</th></tr></thead><tbody>${activity}</tbody></table></div>`;
        document.querySelector('#create-branch-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const formData = new FormData(form);
            try {
                await request(`/superadmin/schools/${schoolId}/branches`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: formData.get('name'), code: formData.get('code')})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        });
        document.querySelectorAll('.branch-access-form').forEach(form => form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!window.confirm('Grant this role access to the selected branch?')) return;
            const formData = new FormData(event.currentTarget);
            try {
                await request(`/superadmin/schools/${event.currentTarget.dataset.school}/members/${event.currentTarget.dataset.user}/branch-access`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({branch_id: Number(formData.get('branch_id')), roles: [formData.get('role')], status: 'active'})});
                window.alert('Branch access granted and audited.');
            } catch (error) {
                window.alert(error.message);
            }
        }));
        document.querySelectorAll('.branch-status').forEach(button => button.addEventListener('click', async event => {
            const target = event.currentTarget;
            const status = target.dataset.status;
            if (!window.confirm(`Are you sure you want to ${status === 'suspended' ? 'suspend' : 'activate'} this branch?`)) return;
            try {
                await request(`/superadmin/schools/${schoolId}/branches/${target.dataset.branch}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({status})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        document.querySelectorAll('.access-status').forEach(button => button.addEventListener('click', async event => {
            const target = event.currentTarget;
            const access = data.access.find(item => String(item.user_id) === String(target.dataset.user) && String(item.branch_id) === String(target.dataset.branch));
            if (!access || !window.confirm(`Are you sure you want to ${target.dataset.status === 'suspended' ? 'suspend' : 'activate'} this branch access?`)) return;
            try {
                await request(`/superadmin/schools/${schoolId}/members/${target.dataset.user}/branch-access`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({branch_id: Number(target.dataset.branch), roles: JSON.parse(access.roles || '[]'), status: target.dataset.status})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
    };
    const load = async () => {
        const response = await fetch('/superadmin/data', {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) throw new Error('Unable to load platform data.');
        const data = await response.json();
        summary.innerHTML = [['schools','Schools'],['branches','Branches'],['members','Members'],['students','Students'],['open_invoices','Open invoices']].map(([key,label]) => `<article><strong>${esc(data.summary[key])}</strong><span>${label}</span></article>`).join('');
        schools.innerHTML = data.schools.map(school => `<tr><td><button class="platform-school-detail" data-id="${school.id}" type="button"><strong>${esc(school.name)}</strong></button><small>${esc(school.slug)}</small></td><td><span class="platform-status ${esc(school.status)}">${esc(school.status)}</span></td><td>${esc(school.branches)}</td><td>${esc(school.members)}</td><td>${esc(school.students)}</td><td>${esc(school.staff)}</td><td>${esc(school.open_invoices)}</td><td><button class="platform-action" data-id="${school.id}" data-status="${school.status === 'active' ? 'suspended' : 'active'}">${school.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`).join('') || '<tr><td colspan="8">No schools registered.</td></tr>';
        audit.innerHTML = data.audit.map(row => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.school_name)}</td><td>${esc(row.actor || 'System')}</td><td>${esc(row.module)}</td><td>${esc(row.action)}</td></tr>`).join('') || '<tr><td colspan="5">No audit events yet.</td></tr>';
        document.querySelectorAll('.platform-school-detail').forEach(button => button.addEventListener('click', () => showSchoolDetail(button.dataset.id).catch(error => { detailContent.innerHTML = esc(error.message); })));
        document.querySelectorAll('.platform-action').forEach(button => button.addEventListener('click', async () => {
            const action = button.dataset.status === 'suspended' ? 'suspend' : 'activate';
            if (!window.confirm(`Are you sure you want to ${action} this school?`)) {
                return;
            }

            button.disabled = true;
            try {
                const response = await fetch(`/superadmin/schools/${button.dataset.id}/status`, {method:'PUT', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({status:button.dataset.status})});
                if (!response.ok) {
                    throw new Error('Unable to update the school status.');
                }
                await load();
            } catch (error) {
                button.disabled = false;
                window.alert(error.message);
            }
        }));
    };
    const loadUsers = async () => {
        const search = document.querySelector('#user-search').value.trim();
        const data = await request(`/superadmin/users?search=${encodeURIComponent(search)}`);
        userRows.innerHTML = data.users.data.map(user => {
            const access = user.is_superadmin ? 'Platform Superadmin' : (user.access.map(item => `${esc(item.school_name)} / ${esc(item.branch_name)} (${esc(item.roles.join(', '))})`).join('<br>') || 'No active branch access');
            const control = user.is_superadmin ? '<span>Protected</span>' : `<button class="platform-user-status" data-id="${esc(user.id)}" data-active="${user.is_active ? '1' : '0'}">${user.is_active ? 'Suspend' : 'Activate'}</button>`;
            return `<tr><td><strong>${esc(user.name)}</strong><small>${esc(user.email)} · ${esc(user.username)}</small></td><td><span class="platform-status ${user.is_active ? 'active' : 'suspended'}">${user.is_active ? 'Active' : 'Suspended'}</span></td><td>${access}</td><td>${control}</td></tr>`;
        }).join('') || '<tr><td colspan="4">No matching users.</td></tr>';
        document.querySelectorAll('.platform-user-status').forEach(button => button.addEventListener('click', async () => {
            const nextActive = button.dataset.active !== '1';
            if (!window.confirm(`Are you sure you want to ${nextActive ? 'activate' : 'suspend'} this account?`)) return;
            try {
                await request(`/superadmin/users/${button.dataset.id}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({is_active: nextActive})});
                await loadUsers();
            } catch (error) {
                window.alert(error.message);
            }
        }));
    };
    document.querySelector('#user-search-form').addEventListener('submit', event => { event.preventDefault(); loadUsers().catch(error => { userRows.innerHTML = `<tr><td colspan="4">${esc(error.message)}</td></tr>`; }); });
    document.querySelector('#create-school-form').addEventListener('submit', async event => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        try {
            await request('/superadmin/schools', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: formData.get('name'), slug: formData.get('slug')})});
            event.currentTarget.reset();
            await load();
        } catch (error) {
            window.alert(error.message);
        }
    });
    document.querySelector('#school-detail-close').addEventListener('click', () => { detail.hidden = true; });
    document.querySelector('.platform-refresh').addEventListener('click', load);
    load().catch(error => { schools.innerHTML = `<tr><td colspan="8">${esc(error.message)}</td></tr>`; });
    loadUsers().catch(error => { userRows.innerHTML = `<tr><td colspan="4">${esc(error.message)}</td></tr>`; });
})();
</script>
</body>
</html>
