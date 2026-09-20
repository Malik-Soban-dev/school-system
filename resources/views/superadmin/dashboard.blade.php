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
        <a href="{{ route('account') }}">Account security</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
    </header>
    <section class="platform-grid" id="platform-summary" aria-live="polite">
        <article><strong>Loading…</strong><span>Schools</span></article><article><strong>Loading…</strong><span>Branches</span></article><article><strong>Loading…</strong><span>Members</span></article><article><strong>Loading…</strong><span>Students</span></article><article><strong>Loading…</strong><span>Open invoices</span></article>
    </section>
    <section class="platform-panel" id="platform-entitlement-alerts" aria-live="polite"><div class="platform-panel-heading"><div><p class="eyebrow">ENTITLEMENTS</p><h2>Capacity and subscription alerts</h2></div></div><div id="entitlement-alert-content">Loading entitlement alerts…</div></section>
    <section class="platform-panel" id="platform-health">
        <div class="platform-panel-heading"><div><p class="eyebrow">OPERATIONS</p><h2>Platform health</h2></div><div><button class="platform-refresh" id="backup-create" type="button">Create encrypted backup</button> <button class="platform-refresh" id="user-export-create" type="button">Export user registry</button> <button class="platform-refresh" id="health-refresh" type="button">Refresh health</button></div></div>
        <div id="health-content">Loading operational health…</div><div id="failed-job-content">Loading failed jobs…</div><div id="export-content">Loading exports…</div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">PLAN CATALOG</p><h2>Platform plans</h2></div></div>
        <form id="create-plan-form"><input name="code" required maxlength="60" pattern="[A-Za-z0-9_-]+" placeholder="Plan code" aria-label="New plan code"><input name="name" required maxlength="100" placeholder="Plan name" aria-label="New plan name"><input name="monthly_price_cents" required type="number" min="0" placeholder="Monthly cents" aria-label="New plan monthly price"><input name="max_branches" type="number" min="1" placeholder="Max branches" aria-label="New plan maximum branches"><input name="max_students" type="number" min="1" placeholder="Max students" aria-label="New plan maximum students"><input name="features" placeholder="Features, e.g. attendance, grades" aria-label="New plan features"><button type="submit">Create plan</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>Plan</th><th>Monthly cents</th><th>Max branches</th><th>Max students</th><th>Features</th><th>Status</th><th>Control</th></tr></thead><tbody id="plan-rows"><tr><td colspan="7">Loading plan catalog…</td></tr></tbody></table></div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">CLIENT BILLING</p><h2>Platform invoices</h2></div></div>
        <form id="platform-invoice-form"><select id="platform-invoice-school" name="school_id" required aria-label="Invoice school"></select><input name="amount_cents" type="number" min="1" required placeholder="Amount in cents" aria-label="Invoice amount in cents"><input name="period_start" type="date" required aria-label="Billing period start"><input name="period_end" type="date" required aria-label="Billing period end"><input name="due_on" type="date" required aria-label="Invoice due date"><input name="notes" maxlength="2000" placeholder="Notes" aria-label="Invoice notes"><button type="submit">Issue invoice</button></form>
        <form id="platform-invoice-filter"><select id="platform-invoice-status" aria-label="Invoice status"><option value="">All statuses</option><option value="issued">Issued</option><option value="paid">Paid</option><option value="overdue">Overdue</option><option value="void">Void</option></select><button type="submit">Filter invoices</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Invoice</th><th>Amount</th><th>Period</th><th>Due</th><th>Status</th><th>Control</th></tr></thead><tbody id="platform-invoice-rows"><tr><td colspan="7">Loading platform invoices…</td></tr></tbody></table></div><div id="platform-invoice-pagination"></div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">SCHOOL REGISTRY</p><h2>Every school</h2></div><div><button class="platform-refresh" type="button">Refresh data</button></div></div>
        <form id="school-registry-filter"><input id="school-registry-search" maxlength="100" placeholder="Search school or slug" aria-label="Search schools"><select id="school-registry-status" aria-label="Filter schools by status"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button type="submit">Filter schools</button></form>
        <form id="create-school-form"><input name="name" required maxlength="150" placeholder="New school name" aria-label="New school name"><input name="slug" required maxlength="80" pattern="[A-Za-z0-9_-]+" placeholder="Slug" aria-label="New school slug"><select id="onboard-plan" name="plan_id" aria-label="Initial school plan"><option value="">Starter trial</option></select><input name="owner_name" maxlength="100" placeholder="Initial owner name (optional)" aria-label="Initial owner name"><input name="owner_email" type="email" maxlength="255" placeholder="Initial owner email (optional)" aria-label="Initial owner email"><select name="owner_role" aria-label="Initial administrator role"><option value="owner">Owner</option><option value="admin">Admin</option></select><button type="submit">Onboard school</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Status</th><th>Plan / subscription</th><th>Branches</th><th>Members</th><th>Students</th><th>Staff</th><th>Teachers</th><th>Open invoices</th><th>Control</th></tr></thead><tbody id="school-rows"><tr><td colspan="10">Loading school registry…</td></tr></tbody></table></div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">BRANCH REGISTRY</p><h2>Every branch</h2></div></div>
        <form id="branch-search-form"><select id="branch-school" aria-label="Filter branches by school"><option value="">All schools</option></select><select id="branch-status" aria-label="Branch status"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><input id="branch-search" maxlength="100" placeholder="Search branch or school" aria-label="Search branches"><button type="submit">Search branches</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Branch</th><th>Status</th><th>Members</th><th>Students</th><th>Staff</th><th>Teachers</th><th>Control</th></tr></thead><tbody id="branch-rows"><tr><td colspan="8">Loading branch registry…</td></tr></tbody></table></div><div id="branch-pagination"></div>
    </section>
    <section class="platform-panel" id="school-detail" hidden>
        <div class="platform-panel-heading"><div><p class="eyebrow">SCHOOL DETAIL</p><h2 id="school-detail-title">Selected school</h2></div><button class="platform-refresh" id="school-detail-close" type="button">Close</button></div>
        <div id="school-detail-content">Select a school to inspect its members and activity.</div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">DATA EXPLORER</p><h2>Inspect school records</h2></div></div>
        <form id="data-explorer-form"><select id="explorer-school" name="school_id" aria-label="School"></select><select id="explorer-branch" name="branch_id" aria-label="Branch"><option value="">All branches</option></select><select id="explorer-module" name="module" aria-label="Data module"><option value="academic_years">Academic years</option><option value="students">Students</option><option value="staff">Staff</option><option value="classes">Classes</option><option value="subjects">Subjects</option><option value="teacher_assignments">Teaching assignments</option><option value="guardian_links">Guardian links</option><option value="attendance">Attendance</option><option value="timetables">Timetables</option><option value="exams">Exams</option><option value="grades">Grades</option><option value="users">Users</option><option value="invoices">Invoices</option><option value="payments">Payments</option><option value="expenses">Expenses</option><option value="leave_requests">Leave requests</option><option value="payroll">Payroll</option><option value="payroll_payments">Payroll payments</option><option value="notices">Notices</option><option value="enrollments">Enrollments</option><option value="invitations">Invitations</option><option value="notifications">Notifications</option><option value="audit">Audit</option></select><input id="explorer-search" name="search" maxlength="100" placeholder="Search records" aria-label="Search records"><button type="submit">Load records</button></form>
        <div class="platform-table-wrap"><table><thead id="explorer-head"><tr><th>Records</th></tr></thead><tbody id="explorer-rows"><tr><td>Select a school and module.</td></tr></tbody></table></div><div id="explorer-pagination"></div>
    </section>
    <section class="platform-panel">
        <div class="platform-panel-heading"><div><p class="eyebrow">PLATFORM ACCOUNTS</p><h2>Every user</h2></div></div>
        <form id="user-search-form"><input id="user-search" name="search" maxlength="100" placeholder="Search name, email or username" aria-label="Search platform users"><select id="user-school" aria-label="Filter users by school"><option value="">All schools</option></select><select id="user-branch" aria-label="Filter users by branch"><option value="">All branches</option></select><select id="user-role" aria-label="Filter users by role"><option value="">All roles</option><option value="superadmin">Superadmin</option><option value="owner">Owner</option><option value="admin">Admin</option><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent</option><option value="accountant">Accountant</option></select><select id="user-status" aria-label="Filter users by account status"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button type="submit">Search</button></form>
        <form id="user-access-form"><select id="user-access-user" required aria-label="User to grant branch access"><option value="">Select a loaded user</option></select><select id="user-access-school" required aria-label="Access school"><option value="">Select school</option></select><select id="user-access-branch" required aria-label="Access branch"><option value="">Select branch</option></select><select id="user-access-role" required aria-label="Access role"><option value="admin">Admin</option><option value="owner">Owner</option><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent</option><option value="accountant">Accountant</option></select><select id="user-access-status" required aria-label="Access status"><option value="active">Active</option><option value="suspended">Suspended</option></select><button type="submit">Grant / update branch access</button></form>
        <div class="platform-table-wrap"><table><thead><tr><th>User</th><th>Status</th><th>School / branch access</th><th>Control</th></tr></thead><tbody id="user-rows"><tr><td colspan="4">Loading platform users…</td></tr></tbody></table></div><div id="user-pagination"></div>
    </section>
    <section class="platform-panel"><div class="platform-panel-heading"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Platform activity</h2></div></div><form id="audit-search-form"><select id="audit-school" aria-label="Audit school"><option value="">All schools and platform events</option></select><select id="audit-branch" aria-label="Audit branch"><option value="">All branches</option></select><input id="audit-search" maxlength="100" placeholder="Search school, actor, module or action" aria-label="Search audit activity"><button type="submit">Search audit</button></form><div class="platform-table-wrap"><table><thead><tr><th>Time</th><th>School</th><th>Branch</th><th>Actor</th><th>Module</th><th>Action</th><th>Changes</th></tr></thead><tbody id="audit-rows"><tr><td colspan="7">Loading audit trail…</td></tr></tbody></table></div><div id="audit-pagination"></div></section>
</main>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const summary = document.querySelector('#platform-summary');
    const schoolRegistryFilter = document.querySelector('#school-registry-filter');
    const schoolRegistrySearch = document.querySelector('#school-registry-search');
    const schoolRegistryStatus = document.querySelector('#school-registry-status');
    const entitlementAlertContent = document.querySelector('#entitlement-alert-content');
    const healthContent = document.querySelector('#health-content');
    const failedJobContent = document.querySelector('#failed-job-content');
    const exportContent = document.querySelector('#export-content');
    const backupCreate = document.querySelector('#backup-create');
    const userExportCreate = document.querySelector('#user-export-create');
    const planRows = document.querySelector('#plan-rows');
    const platformInvoiceSchool = document.querySelector('#platform-invoice-school');
    const platformInvoiceRows = document.querySelector('#platform-invoice-rows');
    const platformInvoiceStatus = document.querySelector('#platform-invoice-status');
    const platformInvoicePagination = document.querySelector('#platform-invoice-pagination');
    const schools = document.querySelector('#school-rows');
    const branchRows = document.querySelector('#branch-rows');
    const branchSchool = document.querySelector('#branch-school');
    const branchStatus = document.querySelector('#branch-status');
    const branchSearch = document.querySelector('#branch-search');
    const branchPagination = document.querySelector('#branch-pagination');
    const audit = document.querySelector('#audit-rows');
    const auditSchool = document.querySelector('#audit-school');
    const auditBranch = document.querySelector('#audit-branch');
    const auditSearch = document.querySelector('#audit-search');
    const auditPagination = document.querySelector('#audit-pagination');
    const detail = document.querySelector('#school-detail');
    const detailTitle = document.querySelector('#school-detail-title');
    const detailContent = document.querySelector('#school-detail-content');
    const userRows = document.querySelector('#user-rows');
    const userPagination = document.querySelector('#user-pagination');
    const userSchool = document.querySelector('#user-school');
    const userBranch = document.querySelector('#user-branch');
    const userRole = document.querySelector('#user-role');
    const userStatus = document.querySelector('#user-status');
    const userAccessForm = document.querySelector('#user-access-form');
    const userAccessUser = document.querySelector('#user-access-user');
    const userAccessSchool = document.querySelector('#user-access-school');
    const userAccessBranch = document.querySelector('#user-access-branch');
    const userAccessRole = document.querySelector('#user-access-role');
    const userAccessStatus = document.querySelector('#user-access-status');
    const explorerForm = document.querySelector('#data-explorer-form');
    const explorerSchool = document.querySelector('#explorer-school');
    const explorerBranch = document.querySelector('#explorer-branch');
    const explorerModule = document.querySelector('#explorer-module');
    ['grade_bands', 'exam_subjects'].forEach(module => {
        if (! explorerModule.querySelector(`option[value="${module}"]`)) {
            explorerModule.insertAdjacentHTML('beforeend', `<option value="${module}">${module === 'grade_bands' ? 'Grading bands' : 'Exam subjects'}</option>`);
        }
    });
    [['notification_deliveries', 'Notification deliveries'], ['settings', 'School settings']].forEach(([module, label]) => {
        if (! explorerModule.querySelector(`option[value="${module}"]`)) {
            explorerModule.insertAdjacentHTML('beforeend', `<option value="${module}">${label}</option>`);
        }
    });
    [['notification_preferences', 'Notification preferences'], ['notification_events', 'Notification events']].forEach(([module, label]) => {
        if (! explorerModule.querySelector(`option[value="${module}"]`)) {
            explorerModule.insertAdjacentHTML('beforeend', `<option value="${module}">${label}</option>`);
        }
    });
    const explorerSearch = document.querySelector('#explorer-search');
    const explorerHead = document.querySelector('#explorer-head');
    const explorerRows = document.querySelector('#explorer-rows');
    const explorerPagination = document.querySelector('#explorer-pagination');
    let platformSchools = [];
    let platformPlans = [];
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const request = async (url, options = {}) => {
        const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json', ...options.headers}, ...options});
        if (!response.ok) {
            throw new Error((await response.json().catch(() => ({}))).message || 'The platform request failed.');
        }
        return response.status === 204 ? null : response.json();
    };
    const filterSchoolRegistry = () => {
        const search = schoolRegistrySearch.value.trim().toLowerCase();
        const status = schoolRegistryStatus.value;
        schools.querySelectorAll('tr[data-school-name]').forEach(row => {
            row.hidden = (search !== '' && !`${row.dataset.schoolName} ${row.dataset.schoolSlug}`.toLowerCase().includes(search)) || (status !== '' && row.dataset.schoolStatus !== status);
        });
    };
    const loadHealth = async () => {
        const data = await request('/superadmin/health');
        const backupRows = data.backups.map(backup => `<tr><td>${esc(backup.name)}</td><td>${esc(backup.bytes)} bytes</td><td>${esc(backup.modified_at)}</td><td><a href="${esc(backup.download_url)}">Download</a> <button class="backup-verify" data-name="${esc(backup.name)}" type="button">Verify integrity</button></td></tr>`).join('') || '<tr><td colspan="4">No encrypted backups found.</td></tr>';
        healthContent.innerHTML = `<p><span class="platform-status ${esc(data.status)}">${esc(data.status)}</span> Database: <strong>${esc(data.database)}</strong> · Active schools: <strong>${esc(data.schools.active)}</strong> · Suspended schools: <strong>${esc(data.schools.suspended)}</strong> · Pending jobs: <strong>${esc(data.queue.pending)}</strong> · Failed jobs: <strong>${esc(data.queue.failed)}</strong> · Sessions: <strong>${esc(data.sessions ?? 'not configured')}</strong> · Last audit: <strong>${esc(data.last_audit_at || 'none')}</strong></p><h3>Recent encrypted backups</h3><div class="platform-table-wrap"><table><thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Control</th></tr></thead><tbody>${backupRows}</tbody></table></div>`;
        healthContent.querySelectorAll('.backup-verify').forEach(button => button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const result = await request('/superadmin/operations/backups/verify', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: button.dataset.name})});
                window.alert(result.message);
            } catch (error) {
                window.alert(error.message);
            } finally {
                button.disabled = false;
            }
        }));
    };
    const loadFailedJobs = async () => {
        const data = await request('/superadmin/operations/failed-jobs?per_page=50');
        failedJobContent.innerHTML = `<h3>Failed job records</h3><div class="platform-table-wrap"><table><thead><tr><th>UUID</th><th>Queue</th><th>Connection</th><th>Failed at</th><th>Control</th></tr></thead><tbody>${data.failed_jobs.data.map(job => `<tr><td>${esc(job.uuid)}</td><td>${esc(job.queue)}</td><td>${esc(job.connection)}</td><td>${esc(job.failed_at)}</td><td><button class="failed-job-retry" data-id="${esc(job.id)}" type="button">Retry job</button> <button class="failed-job-forget" data-id="${esc(job.id)}" type="button">Forget record</button></td></tr>`).join('') || '<tr><td colspan="5">No failed job records.</td></tr>'}</tbody></table></div>`;
        document.querySelectorAll('.failed-job-retry').forEach(button => button.addEventListener('click', async () => { if (!window.confirm('Requeue this failed job? It may run again and should be safe to retry.')) return; try { await request(`/superadmin/operations/failed-jobs/${button.dataset.id}/retry`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf}}); await loadHealth(); await loadFailedJobs(); } catch (error) { window.alert(error.message); } }));
        document.querySelectorAll('.failed-job-forget').forEach(button => button.addEventListener('click', async () => { if (!window.confirm('Remove this failed-job record? The serialized payload is not retried.')) return; try { await request(`/superadmin/operations/failed-jobs/${button.dataset.id}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf}}); await loadHealth(); await loadFailedJobs(); } catch (error) { window.alert(error.message); } }));
    };
    const loadExports = async () => {
        const data = await request('/superadmin/operations/exports?per_page=20');
        exportContent.innerHTML = `<h3>Platform data exports</h3><div class="platform-table-wrap"><table><thead><tr><th>Type</th><th>School</th><th>Created</th><th>Status</th><th>Rows</th><th>Expires</th><th>Control</th></tr></thead><tbody>${data.exports.data.map(item => `<tr><td>${esc(item.type === 'school' ? 'School data' : 'User registry')}</td><td>${esc(item.school_name || 'Platform')}</td><td>${esc(item.created_at)}</td><td><span class="platform-status ${esc(item.status)}">${esc(item.status)}</span>${item.error ? `<small>${esc(item.error)}</small>` : ''}</td><td>${esc(item.row_count ?? '—')}</td><td>${esc(item.expires_at || '—')}</td><td>${item.download_url ? `<a href="${esc(item.download_url)}">Download ${item.type === 'school' ? 'NDJSON' : 'CSV'}</a>` : '<small>Processing…</small>'}</td></tr>`).join('') || '<tr><td colspan="7">No exports created.</td></tr>'}</tbody></table></div>`;
    };
    userExportCreate.addEventListener('click', async () => {
        if (!window.confirm('Queue a complete user and branch-access CSV export? Passwords, MFA secrets and tokens are excluded.')) return;
        userExportCreate.disabled = true;
        try { await request('/superadmin/operations/exports/users', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf}}); await loadExports(); } catch (error) { window.alert(error.message); } finally { userExportCreate.disabled = false; }
    });
    backupCreate.addEventListener('click', async () => {
        if (!window.confirm('Create a new encrypted database backup now?')) return;
        backupCreate.disabled = true;
        try {
            const data = await request('/superadmin/operations/backups', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf}});
            window.alert(`Encrypted backup created: ${data.backup.name}`);
            await loadHealth();
        } catch (error) {
            window.alert(error.message);
        } finally {
            backupCreate.disabled = false;
        }
    });
    const renderPlans = () => {
        planRows.innerHTML = platformPlans.map(plan => `<tr><td><input class="plan-name" value="${esc(plan.name)}" aria-label="Plan name"><small>${esc(plan.code)}</small></td><td><input class="plan-price" type="number" min="0" value="${esc(plan.monthly_price_cents)}" aria-label="Monthly price for ${esc(plan.name)}"></td><td><input class="plan-branches" type="number" min="1" value="${esc(plan.max_branches ?? '')}" aria-label="Maximum branches for ${esc(plan.name)}"></td><td><input class="plan-students" type="number" min="1" value="${esc(plan.max_students ?? '')}" aria-label="Maximum students for ${esc(plan.name)}"></td><td><input class="plan-features" value="${esc((JSON.parse(plan.features || '[]')).join(', '))}" aria-label="Features for ${esc(plan.name)}"><small>attendance, grades, invoices, payroll, notifications, *</small></td><td><select class="plan-status" aria-label="Status for ${esc(plan.name)}"><option value="active" ${plan.status === 'active' ? 'selected' : ''}>Active</option><option value="archived" ${plan.status === 'archived' ? 'selected' : ''}>Archived</option></select></td><td><button class="plan-save" data-id="${esc(plan.id)}" type="button">Save</button></td></tr>`).join('') || '<tr><td colspan="7">No plans registered.</td></tr>';
        document.querySelectorAll('.plan-save').forEach(button => button.addEventListener('click', async event => {
            const row = event.currentTarget.closest('tr');
            try {
                await request(`/superadmin/plans/${event.currentTarget.dataset.id}`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: row.querySelector('.plan-name').value, monthly_price_cents: Number(row.querySelector('.plan-price').value), max_branches: row.querySelector('.plan-branches').value || null, max_students: row.querySelector('.plan-students').value || null, features: row.querySelector('.plan-features').value.split(',').map(value => value.trim()).filter(Boolean), status: row.querySelector('.plan-status').value})});
                window.alert('Plan updated and audited.');
                await load();
            } catch (error) {
                window.alert(error.message);
            }
        }));
    };
    const loadPlatformInvoices = async (page = 1) => {
        const params = new URLSearchParams({page, per_page: 50});
        if (platformInvoiceStatus.value) params.set('status', platformInvoiceStatus.value);
        const data = await request(`/superadmin/billing/invoices?${params}`);
        platformInvoiceRows.innerHTML = data.invoices.data.map(invoice => { const control = invoice.status === 'paid' ? `<small>Paid · ${esc(invoice.payment_reference || 'reference unavailable')}</small>` : invoice.status === 'void' ? '<small>Voided</small>' : `<button class="platform-invoice-paid" data-id="${esc(invoice.id)}" type="button">Mark paid</button> <button class="platform-invoice-void" data-id="${esc(invoice.id)}" type="button">Void</button>`; return `<tr><td>${esc(invoice.school_name)}</td><td>${esc(invoice.invoice_number)}</td><td>${esc(invoice.currency)} ${(Number(invoice.amount_cents) / 100).toFixed(2)}</td><td>${esc(invoice.period_start)} → ${esc(invoice.period_end)}</td><td>${esc(invoice.due_on)}</td><td><span class="platform-status ${esc(invoice.status)}">${esc(invoice.status)}</span></td><td>${control}</td></tr>`; }).join('') || '<tr><td colspan="7">No platform invoices yet.</td></tr>';
        platformInvoicePagination.innerHTML = data.invoices.last_page > 1 ? `<button type="button" data-invoice-page="${data.invoices.current_page - 1}" ${data.invoices.current_page === 1 ? 'disabled' : ''}>Previous</button> <span>Page ${data.invoices.current_page} of ${data.invoices.last_page}</span> <button type="button" data-invoice-page="${data.invoices.current_page + 1}" ${data.invoices.current_page === data.invoices.last_page ? 'disabled' : ''}>Next</button>` : '';
        platformInvoicePagination.querySelectorAll('[data-invoice-page]').forEach(button => button.addEventListener('click', () => loadPlatformInvoices(Number(button.dataset.invoicePage)).catch(error => { platformInvoiceRows.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; })));
        document.querySelectorAll('.platform-invoice-paid').forEach(button => button.addEventListener('click', async () => { const reference = window.prompt('Payment reference'); if (!reference) return; try { await request(`/superadmin/billing/invoices/${button.dataset.id}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({status: 'paid', payment_reference: reference})}); await loadPlatformInvoices(); } catch (error) { window.alert(error.message); } }));
        document.querySelectorAll('.platform-invoice-void').forEach(button => button.addEventListener('click', async () => { if (!window.confirm('Void this platform invoice?')) return; try { await request(`/superadmin/billing/invoices/${button.dataset.id}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({status: 'void'})}); await loadPlatformInvoices(); } catch (error) { window.alert(error.message); } }));
    };
    const showSchoolDetail = async schoolId => {
        detail.hidden = false;
        detailContent.innerHTML = 'Loading school detail…';
        const data = await request(`/superadmin/schools/${schoolId}`);
        detailTitle.textContent = data.school.name;
        const counts = Object.entries(data.counts).map(([key, value]) => `<span><strong>${esc(value)}</strong> ${esc(key)}</span>`).join(' · ');
        const subscription = data.subscription;
        const planOptions = platformPlans.map(plan => `<option value="${esc(plan.id)}" ${String(plan.id) === String(subscription?.plan_id) ? 'selected' : ''}>${esc(plan.name)} · $${(Number(plan.monthly_price_cents) / 100).toFixed(2)}/mo</option>`).join('');
        const branchOptions = data.branches.map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)} (${esc(branch.code)})</option>`).join('');
        const branchRows = data.branches.map(branch => `<tr><td>${esc(branch.name)}${branch.is_default ? ' <small>Default</small>' : ''}</td><td>${esc(branch.code)}</td><td><span class="platform-status ${esc(branch.status)}">${esc(branch.status)}</span></td><td>${esc(branch.students)} students · ${esc(branch.staff)} staff · ${esc(branch.teachers)} teachers · ${esc(branch.classes)} classes</td><td><button class="branch-workspace" data-branch="${esc(branch.id)}">Open workspace</button> <button class="branch-edit" data-branch="${esc(branch.id)}" data-name="${esc(branch.name)}" data-code="${esc(branch.code)}">Edit</button> ${branch.is_default ? '<small>Default</small>' : `<button class="branch-default" data-branch="${esc(branch.id)}">Set default</button>`} <button class="branch-status" data-branch="${esc(branch.id)}" data-status="${branch.status === 'active' ? 'suspended' : 'active'}">${branch.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`).join('') || '<tr><td colspan="5">No branches registered.</td></tr>';
        const members = data.members.map(member => `<tr><td>${esc(member.name)}</td><td>${esc(member.email)}</td><td>${esc(member.is_active ? 'Account active' : 'Account inactive')} · ${esc(member.membership_status)}</td><td><button class="membership-status" data-user="${esc(member.id)}" data-status="${member.membership_status === 'active' ? 'suspended' : 'active'}">${member.membership_status === 'active' ? 'Suspend school access' : 'Activate school access'}</button><form class="branch-access-form" data-school="${esc(data.school.id)}" data-user="${esc(member.id)}"><select name="branch_id" aria-label="Branch for ${esc(member.name)}">${branchOptions}</select><select name="role" aria-label="Role for ${esc(member.name)}"><option value="admin">Admin</option><option value="owner">Owner</option><option value="teacher">Teacher</option><option value="accountant">Accountant</option></select><button type="submit">Grant access</button></form></td></tr>`).join('') || '<tr><td colspan="4">No school members.</td></tr>';
        const invitations = data.invitations.map(invitation => { const expired = new Date(invitation.expires_at) <= new Date(); return `<tr><td>${esc(invitation.name)}</td><td>${esc(invitation.email)}</td><td>${esc(invitation.branch_name || 'Unknown branch')}</td><td>${esc(invitation.roles)}</td><td><span class="platform-status ${expired ? 'suspended' : 'active'}">${expired ? 'Expired' : 'Pending'}</span></td><td>${expired ? '<small>Expired</small>' : `<button class="invitation-revoke" data-id="${esc(invitation.id)}">Revoke</button>`}</td></tr>`; }).join('') || '<tr><td colspan="6">No outstanding invitations.</td></tr>';
        const activity = data.audit.map(row => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.actor || 'System')}</td><td>${esc(row.module)}</td><td>${esc(row.action)}</td></tr>`).join('') || '<tr><td colspan="4">No school activity yet.</td></tr>';
        detailContent.innerHTML = `<p>${counts} <button id="school-export-create" type="button">Export school data</button></p><h3>Subscription</h3><form id="subscription-form"><select name="plan_id" aria-label="Subscription plan" required>${planOptions}</select><select name="status" aria-label="Subscription status"><option value="trialing" ${subscription?.status === 'trialing' ? 'selected' : ''}>Trialing</option><option value="active" ${subscription?.status === 'active' ? 'selected' : ''}>Active</option><option value="past_due" ${subscription?.status === 'past_due' ? 'selected' : ''}>Past due</option><option value="canceled" ${subscription?.status === 'canceled' ? 'selected' : ''}>Canceled</option></select><input name="renews_at" type="date" value="${esc(subscription?.renews_at?.slice(0, 10) || '')}" aria-label="Renewal date"><button type="submit">Save subscription</button></form><h3>Branches</h3><form id="create-branch-form"><input name="name" required maxlength="120" placeholder="Branch name" aria-label="Branch name"><input name="code" required maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="Code" aria-label="Branch code"><button type="submit">Create branch</button></form><div class="platform-table-wrap"><table><thead><tr><th>Name</th><th>Code</th><th>Status</th><th>Records</th><th>Control</th></tr></thead><tbody>${branchRows}</tbody></table></div><h3>Invite a branch user</h3><form id="invite-branch-form"><input name="name" required maxlength="100" placeholder="Full name" aria-label="Invitee name"><input name="email" required type="email" maxlength="255" placeholder="Email" aria-label="Invitee email"><select name="branch_id" aria-label="Invitation branch">${data.branches.filter(branch => branch.status === 'active').map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)}</option>`).join('')}</select><select name="role" aria-label="Invitation role"><option value="admin">Admin</option><option value="owner">Owner</option><option value="teacher">Teacher</option><option value="accountant">Accountant</option></select><button type="submit">Create invitation</button></form><div class="platform-table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Branch access</th></tr></thead><tbody>${members}</tbody></table></div><h3>Current branch grants</h3><div class="platform-table-wrap"><table><thead><tr><th>Member</th><th>Branch</th><th>Roles</th><th>Status</th><th>Control</th></tr></thead><tbody>${data.access.map(access => { const member = data.members.find(item => item.id === access.user_id); return `<tr><td>${esc(member?.name || 'Unknown user')}</td><td>${esc(access.branch_name)}</td><td>${esc(JSON.parse(access.roles || '[]').join(', '))}</td><td>${esc(access.status)}</td><td><button class="access-status" data-user="${esc(access.user_id)}" data-branch="${esc(access.branch_id)}" data-status="${access.status === 'active' ? 'suspended' : 'active'}">${access.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`; }).join('') || '<tr><td colspan="5">No branch grants registered.</td></tr>'}</tbody></table></div><h3>Recent activity</h3><div class="platform-table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Module</th><th>Action</th></tr></thead><tbody>${activity}</tbody></table></div>`;
        document.querySelector('#school-export-create').addEventListener('click', async event => {
            if (!window.confirm('Queue a complete school data export? Authentication secrets and private phone credentials are excluded.')) return;
            event.currentTarget.disabled = true;
            try { await request(`/superadmin/schools/${schoolId}/operations/exports`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf}}); window.alert('School data export queued. Monitor it in Platform health.'); await loadExports(); } catch (error) { window.alert(error.message); } finally { event.currentTarget.disabled = false; }
        });
        const existingUserPanel = document.createElement('div');
        existingUserPanel.innerHTML = `<h3>Grant existing account branch access</h3><form id="existing-user-access-form"><select name="user_id" aria-label="Existing account" required><option value="">Select an account</option>${data.available_users.map(user => `<option value="${esc(user.id)}">${esc(user.name)} · ${esc(user.email)}</option>`).join('')}</select><select name="branch_ids" aria-label="Branches for existing account" required multiple size="${Math.min(Math.max(data.branches.length, 2), 6)}">${branchOptions}</select><select name="role" aria-label="Role for existing account"><option value="admin">Admin</option><option value="owner">Owner</option><option value="teacher">Teacher</option><option value="accountant">Accountant</option></select><button type="submit">Grant selected branches</button></form>`;
        detailContent.prepend(existingUserPanel);
        existingUserPanel.querySelector('#existing-user-access-form').addEventListener('submit', async event => {
            event.preventDefault();
            const formData = new FormData(event.currentTarget);
            try {
                await request(`/superadmin/schools/${schoolId}/members/${formData.get('user_id')}/branch-access/bulk`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({branch_ids: formData.getAll('branch_ids').map(Number), roles: [formData.get('role')], status: 'active'})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        });
        const invitationPanel = document.createElement('div');
        invitationPanel.innerHTML = `<h3>Outstanding invitations</h3><div class="platform-table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Branch</th><th>Roles</th><th>Status</th><th>Control</th></tr></thead><tbody>${invitations}</tbody></table></div>`;
        detailContent.append(invitationPanel);
        invitationPanel.querySelectorAll('.invitation-revoke').forEach(button => button.addEventListener('click', async () => {
            if (!window.confirm('Revoke this invitation? The old link will stop working.')) return;
            try {
                await request(`/superadmin/schools/${schoolId}/invitations/${button.dataset.id}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf}});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        const editSchool = document.createElement('button');
        editSchool.type = 'button';
        editSchool.textContent = 'Edit school profile';
        const schoolActions = document.createElement('p');
        schoolActions.append(editSchool);
        detailContent.prepend(schoolActions);
        editSchool.addEventListener('click', async () => {
            const name = window.prompt('School name', data.school.name);
            if (name === null) return;
            const slug = window.prompt('School slug', data.school.slug);
            if (slug === null) return;
            try {
                await request(`/superadmin/schools/${schoolId}`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name, slug})});
                await load();
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        });
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
        document.querySelector('#subscription-form').addEventListener('submit', async event => {
            event.preventDefault();
            const formData = new FormData(event.currentTarget);
            try {
                await request(`/superadmin/schools/${schoolId}/subscription`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({plan_id: Number(formData.get('plan_id')), status: formData.get('status'), renews_at: formData.get('renews_at') || null})});
                window.alert('Subscription updated and audited.');
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        });
        document.querySelector('#invite-branch-form').addEventListener('submit', async event => {
            event.preventDefault();
            const formData = new FormData(event.currentTarget);
            try {
                const invitation = await request(`/superadmin/schools/${schoolId}/branches/${formData.get('branch_id')}/invitations`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: formData.get('name'), email: formData.get('email'), roles: [formData.get('role')]})});
                window.prompt('Copy this private, single-use invitation link. It expires in 48 hours.', invitation.url);
                event.currentTarget.reset();
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
        document.querySelectorAll('.branch-default').forEach(button => button.addEventListener('click', async event => {
            if (!window.confirm('Make this active branch the school default?')) return;
            try {
                await request(`/superadmin/schools/${schoolId}/branches/${event.currentTarget.dataset.branch}/default`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf}});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        document.querySelectorAll('.branch-edit').forEach(button => button.addEventListener('click', async event => {
            const target = event.currentTarget;
            const name = window.prompt('Branch name', target.dataset.name);
            if (name === null) return;
            const code = window.prompt('Branch code', target.dataset.code);
            if (code === null) return;
            try {
                await request(`/superadmin/schools/${schoolId}/branches/${target.dataset.branch}`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name, code})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        document.querySelectorAll('.branch-workspace').forEach(button => button.addEventListener('click', async event => {
            button.disabled = true;
            try {
                await request('/portal/context', {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({school_id: Number(schoolId), branch_id: Number(event.currentTarget.dataset.branch)})});
                window.location.href = '/dashboard';
            } catch (error) {
                button.disabled = false;
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
        document.querySelectorAll('.membership-status').forEach(button => button.addEventListener('click', async event => {
            const target = event.currentTarget;
            const status = target.dataset.status;
            if (!window.confirm(`Are you sure you want to ${status === 'suspended' ? 'suspend' : 'activate'} this user's school access?`)) return;
            try {
                await request(`/superadmin/schools/${schoolId}/members/${target.dataset.user}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({status})});
                await showSchoolDetail(schoolId);
            } catch (error) {
                window.alert(error.message);
            }
        }));
    };
    const loadAudit = async (page = 1) => {
        const params = new URLSearchParams({page, per_page: 50});
        if (auditSchool.value) params.set('school_id', auditSchool.value);
        if (auditBranch.value) params.set('branch_id', auditBranch.value);
        if (auditSearch.value.trim()) params.set('search', auditSearch.value.trim());
        const data = await request(`/superadmin/audit?${params}`);
        audit.innerHTML = data.audit.data.map(row => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.school_name)}</td><td>${esc(row.branch_name || 'School-wide')}</td><td>${esc(row.actor || 'System')}</td><td>${esc(row.module)}</td><td>${esc(row.action)}</td><td>${row.changes ? `<details><summary>View</summary><pre>${esc(row.changes)}</pre></details>` : '<small>None</small>'}</td></tr>`).join('') || '<tr><td colspan="7">No audit events found.</td></tr>';
        auditPagination.innerHTML = data.audit.last_page > 1 ? `<button type="button" data-audit-page="${data.audit.current_page - 1}" ${data.audit.current_page === 1 ? 'disabled' : ''}>Previous</button> <span>Page ${data.audit.current_page} of ${data.audit.last_page}</span> <button type="button" data-audit-page="${data.audit.current_page + 1}" ${data.audit.current_page === data.audit.last_page ? 'disabled' : ''}>Next</button>` : '';
        auditPagination.querySelectorAll('[data-audit-page]').forEach(button => button.addEventListener('click', () => loadAudit(Number(button.dataset.auditPage)).catch(error => { audit.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; })));
    };
    const updateAuditBranches = () => {
        const school = platformSchools.find(item => String(item.id) === String(auditSchool.value));
        auditBranch.innerHTML = '<option value="">All branches</option>' + (school?.branch_options || []).map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)}</option>`).join('');
    };
    const load = async () => {
        const response = await fetch('/superadmin/data', {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) throw new Error('Unable to load platform data.');
        const data = await response.json();
        platformSchools = data.schools;
        platformPlans = data.plans || [];
        const entitlementAlerts = data.entitlement_alerts || [];
        entitlementAlertContent.innerHTML = entitlementAlerts.length ? `<div class="platform-table-wrap"><table><thead><tr><th>School</th><th>Type</th><th>Message</th><th>Usage</th></tr></thead><tbody>${entitlementAlerts.map(alert => `<tr><td>${esc(alert.school_name)}</td><td><span class="platform-status suspended">${esc(alert.type.replaceAll('_', ' '))}</span></td><td>${esc(alert.message)}</td><td>${alert.usage !== undefined ? `${esc(alert.usage)} / ${esc(alert.limit)}` : '—'}</td></tr>`).join('')}</tbody></table></div>` : '<p class="platform-status active">No capacity or subscription alerts.</p>';
        document.querySelector('#onboard-plan').innerHTML = '<option value="">Starter trial</option>' + platformPlans.filter(plan => plan.status === 'active').map(plan => `<option value="${esc(plan.id)}">${esc(plan.name)} trial</option>`).join('');
        platformInvoiceSchool.innerHTML = platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        auditSchool.innerHTML = '<option value="">All schools and platform events</option>' + platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        const selectedUserSchool = userSchool.value;
        userSchool.innerHTML = '<option value="">All schools</option>' + platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        if (platformSchools.some(school => String(school.id) === selectedUserSchool)) userSchool.value = selectedUserSchool;
        userSchool.dispatchEvent(new Event('change'));
        const selectedBranchSchool = branchSchool.value;
        branchSchool.innerHTML = '<option value="">All schools</option>' + platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        if (platformSchools.some(school => String(school.id) === selectedBranchSchool)) branchSchool.value = selectedBranchSchool;
        const selectedAccessSchool = userAccessSchool.value;
        userAccessSchool.innerHTML = '<option value="">Select school</option>' + platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        if (platformSchools.some(school => String(school.id) === selectedAccessSchool)) userAccessSchool.value = selectedAccessSchool;
        updateUserAccessBranches();
        updateAuditBranches();
        renderPlans();
        const selectedSchool = explorerSchool.value;
        explorerSchool.innerHTML = platformSchools.map(school => `<option value="${esc(school.id)}">${esc(school.name)}</option>`).join('');
        if (platformSchools.some(school => String(school.id) === selectedSchool)) explorerSchool.value = selectedSchool;
        updateExplorerBranches();
        loadExplorer().catch(error => { explorerRows.innerHTML = `<tr><td>${esc(error.message)}</td></tr>`; });
        const mrr = Number(data.billing?.mrr_cents || 0) / 100;
        summary.innerHTML = [['schools','Schools'],['branches','Branches'],['members','Memberships'],['accounts','Accounts'],['students','Students'],['staff','Staff'],['teachers','Teachers'],['classes','Classes'],['guardians','Guardians'],['open_invoices','Open invoices'],['mrr','Projected MRR'],['past_due','Past due'],['entitlement_alerts','Alerts']].map(([key,label]) => { const value = key === 'mrr' ? `$${mrr.toFixed(2)}` : key === 'past_due' ? (data.billing?.subscriptions?.past_due || 0) : data.summary[key]; return `<article><strong>${esc(value)}</strong><span>${label}</span></article>`; }).join('');
        schools.innerHTML = data.schools.map(school => { const defaultBranch = school.branch_options.find(branch => branch.is_default) || school.branch_options[0]; const branchLimit = school.max_branches ? `${esc(school.branches)} / ${esc(school.max_branches)}` : `${esc(school.branches)} / unlimited`; const studentLimit = school.max_students ? `${esc(school.students)} / ${esc(school.max_students)}` : `${esc(school.students)} / unlimited`; return `<tr data-school-name="${esc(school.name)}" data-school-slug="${esc(school.slug)}" data-school-status="${esc(school.status)}"><td><button class="platform-school-detail" data-id="${school.id}" type="button"><strong>${esc(school.name)}</strong></button><small>${esc(school.slug)}</small></td><td><span class="platform-status ${esc(school.status)}">${esc(school.status)}</span></td><td>${esc(school.plan_name || 'No plan')}<small>${esc(school.subscription_status || 'unassigned')}</small></td><td>${branchLimit}</td><td>${esc(school.members)}</td><td>${studentLimit}</td><td>${esc(school.staff)}</td><td>${esc(school.teachers)}</td><td>${esc(school.open_invoices)}</td><td>${defaultBranch ? `<button class="platform-workspace" data-school="${esc(school.id)}" data-branch="${esc(defaultBranch.id)}">Open workspace</button>` : ''} <button class="platform-action" data-id="${school.id}" data-status="${school.status === 'active' ? 'suspended' : 'active'}">${school.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`; }).join('') || '<tr><td colspan="10">No schools registered.</td></tr>';
        filterSchoolRegistry();
        loadAudit().catch(error => { audit.innerHTML = `<tr><td colspan="5">${esc(error.message)}</td></tr>`; });
        loadPlatformInvoices().catch(error => { platformInvoiceRows.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; });
        document.querySelectorAll('.platform-school-detail').forEach(button => button.addEventListener('click', () => showSchoolDetail(button.dataset.id).catch(error => { detailContent.innerHTML = esc(error.message); })));
        document.querySelectorAll('.platform-workspace').forEach(button => button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                await request('/portal/context', {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({school_id: Number(button.dataset.school), branch_id: Number(button.dataset.branch)})});
                window.location.href = '/dashboard';
            } catch (error) {
                button.disabled = false;
                window.alert(error.message);
            }
        }));
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
    const loadUsers = async (page = 1) => {
        const search = document.querySelector('#user-search').value.trim();
        const params = new URLSearchParams({page, per_page: 50});
        if (search) params.set('search', search);
        if (userSchool.value) params.set('school_id', userSchool.value);
        if (userBranch.value) params.set('branch_id', userBranch.value);
        if (userRole.value) params.set('role', userRole.value);
        if (userStatus.value) params.set('status', userStatus.value);
        const data = await request(`/superadmin/users?${params}`);
        const selectedAccessUser = userAccessUser.value;
        userAccessUser.innerHTML = '<option value="">Select a loaded user</option>' + data.users.data.filter(user => !user.is_superadmin).map(user => `<option value="${esc(user.id)}">${esc(user.name)} · ${esc(user.email || user.username || '')}</option>`).join('');
        if (data.users.data.some(user => String(user.id) === selectedAccessUser && !user.is_superadmin)) userAccessUser.value = selectedAccessUser;
        userRows.innerHTML = data.users.data.map(user => {
            const access = user.is_superadmin ? 'Platform Superadmin' : (user.access.map(item => `${esc(item.school_name)} / ${esc(item.branch_name)} (${esc(item.roles.join(', '))})`).join('<br>') || 'No active branch access');
            const control = user.is_superadmin ? '<span>Protected</span>' : `<button class="platform-user-edit" data-id="${esc(user.id)}" data-name="${esc(user.name)}" data-email="${esc(user.email || '')}" data-username="${esc(user.username || '')}">Edit profile</button> <button class="platform-user-reset" data-id="${esc(user.id)}">Create reset link</button> <button class="platform-user-revoke" data-id="${esc(user.id)}">Revoke sessions</button> <button class="platform-user-status" data-id="${esc(user.id)}" data-active="${user.is_active ? '1' : '0'}">${user.is_active ? 'Suspend' : 'Activate'}</button>`;
            return `<tr><td><strong>${esc(user.name)}</strong><small>${esc(user.email)} · ${esc(user.username)}</small></td><td><span class="platform-status ${user.is_active ? 'active' : 'suspended'}">${user.is_active ? 'Active' : 'Suspended'}</span></td><td>${access}</td><td>${control}</td></tr>`;
        }).join('') || '<tr><td colspan="4">No matching users.</td></tr>';
        userPagination.innerHTML = data.users.last_page > 1 ? `<button type="button" data-user-page="${data.users.current_page - 1}" ${data.users.current_page === 1 ? 'disabled' : ''}>Previous</button> <span>Page ${data.users.current_page} of ${data.users.last_page}</span> <button type="button" data-user-page="${data.users.current_page + 1}" ${data.users.current_page === data.users.last_page ? 'disabled' : ''}>Next</button>` : '';
        userPagination.querySelectorAll('[data-user-page]').forEach(button => button.addEventListener('click', () => loadUsers(Number(button.dataset.userPage)).catch(error => { userRows.innerHTML = `<tr><td colspan="4">${esc(error.message)}</td></tr>`; })));
        document.querySelectorAll('.platform-user-edit').forEach(button => button.addEventListener('click', async () => {
            const name = window.prompt('User name', button.dataset.name);
            if (name === null) return;
            const email = window.prompt('Email address (optional)', button.dataset.email);
            if (email === null) return;
            const username = window.prompt('Username (lowercase, optional)', button.dataset.username);
            if (username === null) return;
            try {
                await request(`/superadmin/users/${button.dataset.id}`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name, email: email || null, username: username || null})});
                await loadUsers();
            } catch (error) {
                window.alert(error.message);
            }
        }));
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
        document.querySelectorAll('.platform-user-reset').forEach(button => button.addEventListener('click', async () => {
            if (!window.confirm('Create a one-time password reset link and sign this user out of all devices?')) return;
            try {
                const data = await request(`/superadmin/users/${button.dataset.id}/password-reset`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}});
                window.prompt('Share this private reset link with the verified user. It expires in 60 minutes.', data.url);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        document.querySelectorAll('.platform-user-revoke').forEach(button => button.addEventListener('click', async () => {
            if (!window.confirm('Revoke all active sessions for this client account?')) return;
            try { const data = await request(`/superadmin/users/${button.dataset.id}/sessions/revoke`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf}}); window.alert(`${data.revoked_sessions} active session(s) revoked and audited.`); } catch (error) { window.alert(error.message); }
        }));
    };
    const loadBranches = async (page = 1) => {
        const params = new URLSearchParams({page, per_page: 50});
        if (branchSchool.value) params.set('school_id', branchSchool.value);
        if (branchStatus.value) params.set('status', branchStatus.value);
        if (branchSearch.value.trim()) params.set('search', branchSearch.value.trim());
        const data = await request(`/superadmin/branches?${params}`);
        branchRows.innerHTML = data.branches.data.map(branch => `<tr><td>${esc(branch.school_name)}</td><td><strong>${esc(branch.name)}</strong><small>${esc(branch.code)}${branch.is_default ? ' · Default' : ''}</small></td><td><span class="platform-status ${esc(branch.status)}">${esc(branch.status)}</span></td><td>${esc(branch.members)}</td><td>${esc(branch.students)}</td><td>${esc(branch.staff)}</td><td>${esc(branch.teachers)}</td><td><button class="platform-branch-workspace" data-school="${esc(branch.school_id)}" data-branch="${esc(branch.id)}" type="button">Open workspace</button> <button class="platform-branch-status" data-school="${esc(branch.school_id)}" data-branch="${esc(branch.id)}" data-status="${branch.status === 'active' ? 'suspended' : 'active'}">${branch.status === 'active' ? 'Suspend' : 'Activate'}</button></td></tr>`).join('') || '<tr><td colspan="8">No matching branches.</td></tr>';
        branchPagination.innerHTML = data.branches.last_page > 1 ? `<button type="button" data-branch-page="${data.branches.current_page - 1}" ${data.branches.current_page === 1 ? 'disabled' : ''}>Previous</button> <span>Page ${data.branches.current_page} of ${data.branches.last_page}</span> <button type="button" data-branch-page="${data.branches.current_page + 1}" ${data.branches.current_page === data.branches.last_page ? 'disabled' : ''}>Next</button>` : '';
        branchPagination.querySelectorAll('[data-branch-page]').forEach(button => button.addEventListener('click', () => loadBranches(Number(button.dataset.branchPage)).catch(error => { branchRows.innerHTML = `<tr><td colspan="8">${esc(error.message)}</td></tr>`; })));
        branchRows.querySelectorAll('.platform-branch-workspace').forEach(button => button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                await request('/portal/context', {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({school_id: Number(button.dataset.school), branch_id: Number(button.dataset.branch)})});
                window.location.href = '/dashboard';
            } catch (error) {
                button.disabled = false;
                window.alert(error.message);
            }
        }));
        branchRows.querySelectorAll('.platform-branch-status').forEach(button => button.addEventListener('click', async () => {
            const status = button.dataset.status;
            if (!window.confirm(`Are you sure you want to ${status === 'suspended' ? 'suspend' : 'activate'} this branch?`)) return;
            button.disabled = true;
            try {
                await request(`/superadmin/schools/${button.dataset.school}/branches/${button.dataset.branch}/status`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({status})});
                await loadBranches();
            } catch (error) {
                button.disabled = false;
                window.alert(error.message);
            }
        }));
    };
    const updateExplorerBranches = () => {
        const school = platformSchools.find(item => String(item.id) === String(explorerSchool.value));
        explorerBranch.innerHTML = '<option value="">All branches</option>' + (school?.branch_options || []).map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)} (${esc(branch.code)})${branch.status === 'suspended' ? ' · suspended' : ''}</option>`).join('');
    };
    const loadExplorer = async (page = 1) => {
        if (!explorerSchool.value) return;
        const params = new URLSearchParams({search: explorerSearch.value.trim(), page, per_page: 50});
        if (explorerBranch.value) params.set('branch_id', explorerBranch.value);
        const data = await request(`/superadmin/schools/${explorerSchool.value}/records/${explorerModule.value}?${params}`);
        const records = data.records.data || [];
        if (!records.length) {
            explorerHead.innerHTML = '<tr><th>Records</th></tr>';
            explorerRows.innerHTML = '<tr><td>No matching records.</td></tr>';
            explorerPagination.innerHTML = '';
            return;
        }
        const columns = Object.keys(records[0]);
        explorerHead.innerHTML = `<tr>${columns.map(column => `<th>${esc(column.replaceAll('_', ' '))}</th>`).join('')}</tr>`;
        explorerRows.innerHTML = records.map(record => `<tr>${columns.map(column => `<td>${esc(typeof record[column] === 'object' ? JSON.stringify(record[column]) : record[column])}</td>`).join('')}</tr>`).join('');
        explorerPagination.innerHTML = data.records.last_page > 1 ? `<button type="button" data-explorer-page="${data.records.current_page - 1}" ${data.records.current_page === 1 ? 'disabled' : ''}>Previous</button> <span>Page ${data.records.current_page} of ${data.records.last_page}</span> <button type="button" data-explorer-page="${data.records.current_page + 1}" ${data.records.current_page === data.records.last_page ? 'disabled' : ''}>Next</button>` : '';
        explorerPagination.querySelectorAll('[data-explorer-page]').forEach(button => button.addEventListener('click', () => loadExplorer(Number(button.dataset.explorerPage)).catch(error => { explorerRows.innerHTML = `<tr><td>${esc(error.message)}</td></tr>`; })));
    };
    document.querySelector('#create-plan-form').addEventListener('submit', async event => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        try {
            await request('/superadmin/plans', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({code: formData.get('code'), name: formData.get('name'), monthly_price_cents: Number(formData.get('monthly_price_cents')), max_branches: formData.get('max_branches') || null, max_students: formData.get('max_students') || null, features: String(formData.get('features') || '').split(',').map(value => value.trim()).filter(Boolean)})});
            event.currentTarget.reset();
            await load();
        } catch (error) {
            window.alert(error.message);
        }
    });
    document.querySelector('#user-search-form').addEventListener('submit', event => { event.preventDefault(); loadUsers().catch(error => { userRows.innerHTML = `<tr><td colspan="4">${esc(error.message)}</td></tr>`; }); });
    schoolRegistryFilter.addEventListener('submit', event => { event.preventDefault(); filterSchoolRegistry(); });
    userSchool.addEventListener('change', () => { const school = platformSchools.find(item => String(item.id) === String(userSchool.value)); userBranch.innerHTML = '<option value="">All branches</option>' + (school?.branch_options || []).map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)}</option>`).join(''); });
    const updateUserAccessBranches = () => { const school = platformSchools.find(item => String(item.id) === String(userAccessSchool.value)); userAccessBranch.innerHTML = '<option value="">Select branch</option>' + (school?.branch_options || []).map(branch => `<option value="${esc(branch.id)}">${esc(branch.name)}</option>`).join(''); };
    userAccessSchool.addEventListener('change', updateUserAccessBranches);
    userAccessForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!userAccessUser.value || !userAccessSchool.value || !userAccessBranch.value) return;
        const formData = new FormData(event.currentTarget);
        try {
            await request(`/superadmin/users/${userAccessUser.value}/branch-access`, {method: 'PUT', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({school_id: Number(userAccessSchool.value), branch_id: Number(userAccessBranch.value), roles: [userAccessRole.value], status: userAccessStatus.value})});
            window.alert('User branch access updated and audited.');
            await loadUsers();
        } catch (error) {
            window.alert(error.message);
        }
    });
    document.querySelector('#branch-search-form').addEventListener('submit', event => { event.preventDefault(); loadBranches().catch(error => { branchRows.innerHTML = `<tr><td colspan="8">${esc(error.message)}</td></tr>`; }); });
    branchSchool.addEventListener('change', () => loadBranches().catch(error => { branchRows.innerHTML = `<tr><td colspan="8">${esc(error.message)}</td></tr>`; }));
    document.querySelector('#audit-search-form').addEventListener('submit', event => { event.preventDefault(); loadAudit().catch(error => { audit.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; }); });
    auditSchool.addEventListener('change', () => { auditBranch.value = ''; updateAuditBranches(); });
    explorerSchool.addEventListener('change', updateExplorerBranches);
    explorerForm.addEventListener('submit', event => { event.preventDefault(); loadExplorer().catch(error => { explorerRows.innerHTML = `<tr><td>${esc(error.message)}</td></tr>`; }); });
    document.querySelector('#create-school-form').addEventListener('submit', async event => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        try {
            const result = await request('/superadmin/schools', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({name: formData.get('name'), slug: formData.get('slug'), plan_id: formData.get('plan_id') ? Number(formData.get('plan_id')) : null, owner_name: formData.get('owner_name') || null, owner_email: formData.get('owner_email') || null, owner_role: formData.get('owner_role') || 'owner'})});
            if (result.invitation?.url) window.prompt('Copy this private initial administrator invitation link. It expires in 48 hours.', result.invitation.url);
            event.currentTarget.reset();
            await load();
        } catch (error) {
            window.alert(error.message);
        }
    });
    document.querySelector('#platform-invoice-form').addEventListener('submit', async event => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        try {
            await request(`/superadmin/schools/${formData.get('school_id')}/billing/invoices`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json'}, body: JSON.stringify({amount_cents: Number(formData.get('amount_cents')), period_start: formData.get('period_start'), period_end: formData.get('period_end'), due_on: formData.get('due_on'), notes: formData.get('notes') || null})});
            event.currentTarget.reset();
            await loadPlatformInvoices();
        } catch (error) {
            window.alert(error.message);
        }
    });
    document.querySelector('#platform-invoice-filter').addEventListener('submit', event => { event.preventDefault(); loadPlatformInvoices().catch(error => { platformInvoiceRows.innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`; }); });
    document.querySelector('#school-detail-close').addEventListener('click', () => { detail.hidden = true; });
    document.querySelector('.platform-refresh').addEventListener('click', load);
    document.querySelector('#health-refresh').addEventListener('click', () => { loadHealth().catch(error => { healthContent.textContent = error.message; }); loadFailedJobs().catch(error => { failedJobContent.textContent = error.message; }); });
    load().catch(error => { schools.innerHTML = `<tr><td colspan="9">${esc(error.message)}</td></tr>`; });
    loadHealth().catch(error => { healthContent.textContent = error.message; });
    loadFailedJobs().catch(error => { failedJobContent.textContent = error.message; });
    loadExports().catch(error => { exportContent.textContent = error.message; });
    loadUsers().catch(error => { userRows.innerHTML = `<tr><td colspan="4">${esc(error.message)}</td></tr>`; });
    loadBranches().catch(error => { branchRows.innerHTML = `<tr><td colspan="8">${esc(error.message)}</td></tr>`; });
})();
</script>
</body>
</html>
