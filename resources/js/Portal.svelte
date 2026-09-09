<script>
    import { onMount } from 'svelte';
    import { Button, Modal, Spinner } from 'flowbite-svelte';

    let meta = $state(null), section = $state('overview'), rows = $state([]), total = $state(0), page = $state(1);
    let loading = $state(true), busy = $state(false), error = $state(''), message = $state(''), search = $state('');
    let menu = $state(false), editor = $state(false), draft = $state({}), editing = $state(null), errors = $state({});
    let guide = $state(false), step = $state(0), invite = $state(false), inviteUrl = $state(''), access = $state(false);
    let personToEdit = $state(null), selectedRoles = $state([]);
    let attendanceSheet = $state(false), attendanceRecords = $state([]), attendanceClass = $state('');
    let definition = $derived(meta?.modules.find(m => m.key === section));
    let title = $derived(definition?.label ?? ({overview: 'Your school, in one place', people: 'People & access', invitations: 'Invitations', settings: 'School settings', audit: 'Activity history'}[section] ?? section));
    let pageHelp = $derived(definition && !definition.canWrite ? ({students:'View the students connected to your account. Contact your school if a student is missing.', attendance:'See recorded attendance for the students connected to your account.', grades:'Your school publishes reviewed results here. Draft results remain private.', invoices:'View your fee invoices and outstanding balances. Contact the school office to arrange payment.', payments:'View recorded payments and print your receipts.', timetables:'Check class times, subjects and rooms for your school week.', exams:'View the exams your school has shared with you.', notices:'Read the latest messages shared with your school community.'}[section] ?? definition.help) : definition?.help);
    let steps = $derived([
        {title: `Welcome to ${section === 'overview' ? 'your workspace' : title}`, body: pageHelp ?? 'Use the menu to open your school tools. You only see information your role is allowed to access.'},
        {title: 'Find what you need', body: definition ? 'Search by a name or reference. Each card shows a record. Use Previous and Next to move through the results.' : 'Start with academic years, then classes and subjects. Invite people and link their accounts to their school records.'},
        {title: 'Work with confidence', body: definition?.canWrite ? `Choose Add ${definition.singular} to create a record. Fields marked * are required. Save your changes before leaving the form.` : 'Your school administrator manages access and assignments. If your page is empty, ask them to link your account to the right student or class.'},
        {title: 'Help is always here', body: 'Open Page guide any time to replay these tips. On a phone, the Menu button opens all your tools. Account lets you change your password.'}
    ]);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let latestRequest = 0;
    async function api(path, method = 'GET', body) {
        const response = await fetch(path, {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, ...(body ? {body: JSON.stringify(body)} : {})});
        if ([401, 419].includes(response.status)) { location.assign('/login'); throw new Error('Your session ended. Please sign in again.'); }
        const data = await response.json();
        if (!response.ok) { const e = new Error(data.message ?? 'Unable to complete this action. Please try again.'); e.errors = data.errors ?? {}; throw e; }
        return data;
    }
    async function refreshMeta() { meta = await api('/portal/meta'); }
    async function loadRows() {
        const requestNumber = ++latestRequest;
        const activeDefinition = definition;
        const activeSection = section;
        loading = true; error = '';
        try {
            if (activeDefinition) { const data = await api(`/portal/records/${activeSection}?page=${page}&search=${encodeURIComponent(search)}`); if (requestNumber !== latestRequest) return; rows = data.rows; total = data.total; }
            else if (['people', 'audit', 'invitations'].includes(activeSection)) { const data = await api(`/portal/${activeSection === 'people' ? 'users' : activeSection}?page=${page}`); if (requestNumber !== latestRequest) return; const result = data.users ?? data.rows; rows = result.data; total = result.total; }
            else { rows = []; total = 0; }
        } catch (e) { if (requestNumber === latestRequest) error = e.message; } finally { if (requestNumber === latestRequest) loading = false; }
    }
    async function navigate(key) {
        section = key; page = 1; search = ''; menu = false; message = ''; await loadRows();
        if (key === section && key !== 'audit' && !meta.user.tutorials?.includes(key)) { step = 0; guide = true; }
    }
    async function finishGuide() {
        const completedSection = section;
        guide = false;
        meta.user.tutorials = [...(meta.user.tutorials ?? []), completedSection];
        try { await api('/portal/tutorial', 'PUT', {module: completedSection}); } catch(e) { error = e.message; }
    }
    function openEditor(row = null) {
        editing = row?.id ?? null; errors = {}; draft = {};
        for (const field of definition.fields) {
            draft[field.name] = row ? (field.type === 'money' ? (row[field.name] / 100).toFixed(2) : row[field.name] ?? '') : (field.type === 'select' ? field.choices[0] : '');
        }
        editor = true;
    }
    async function saveRecord(event) {
        event.preventDefault(); busy = true; errors = {};
        try { await api(`/portal/records/${section}${editing ? '/' + editing : ''}`, editing ? 'PUT' : 'POST', draft); editor = false; message = 'Record saved successfully.'; await refreshMeta(); await loadRows(); }
        catch(e) { errors = e.errors; errors.form = [e.message]; } finally { busy = false; }
    }
    function display(field, value) {
        if (value === null || value === '') return '—';
        if (field.type === 'relation') return meta.options[field.relation]?.find(o => String(o.value) === String(value))?.name ?? `#${value}`;
        if (field.type === 'money') return `${meta.settings.currency ?? ''} ${(value / 100).toFixed(2)}`;
        return String(value).replaceAll('_', ' ');
    }
    async function createInvite(event) {
        event.preventDefault(); busy = true; errors = {};
        try { const result = await api('/portal/invitations', 'POST', draft); inviteUrl = result.url; message = result.message; }
        catch(e) { errors = e.errors; errors.form = [e.message]; } finally { busy = false; }
    }
    async function updateAccess(person) {
        busy = true; error = '';
        try { const result = await api(`/portal/users/${person.id}`, 'PUT', {roles: person.roles, is_active: !person.is_active}); message = result.message; await loadRows(); await refreshMeta(); } catch(e) { error = e.message; } finally { busy = false; }
    }
    async function revokeInvitation(invitation) {
        busy = true; error = '';
        try { const result = await api(`/portal/invitations/${invitation.id}`, 'DELETE'); message = result.message; await loadRows(); }
        catch(e) { error = e.message; } finally { busy = false; }
    }
    async function saveAccess(event) {
        event.preventDefault(); busy = true; errors = {};
        try { const result = await api(`/portal/users/${personToEdit.id}`, 'PUT', {roles:selectedRoles, is_active:personToEdit.is_active}); access = false; message = result.message; await loadRows(); await refreshMeta(); }
        catch(e) { errors.form = [Object.values(e.errors).flat().join(' ') || e.message]; } finally { busy = false; }
    }
    async function openAttendance() {
        errors = {}; busy = true;
        try {
            const students = await api(`/portal/attendance/roster?class_id=${attendanceClass}`);
            attendanceRecords = students.rows.map(s => ({student_id:s.id, name:s.name, status:''}));
            attendanceSheet = true;
        } catch(e) { error = e.message; } finally { busy = false; }
    }
    async function saveAttendance(event) {
        event.preventDefault(); busy = true; errors = {};
        try { const result = await api('/portal/attendance/batch','PUT',{records:attendanceRecords.filter(r => r.status).map(r => ({student_id:r.student_id,status:r.status}))}); attendanceSheet = false; message = result.message; await loadRows(); }
        catch(e) { errors.form = [Object.values(e.errors).flat().join(' ') || e.message]; } finally { busy = false; }
    }
    async function saveSettings(event) {
        event.preventDefault(); busy = true; error = '';
        try { await api('/portal/settings', 'PUT', meta.settings); message = 'School settings saved.'; } catch(e) { error = Object.values(e.errors).flat().join(' ') || e.message; } finally { busy = false; }
    }
    onMount(async () => { try { await refreshMeta(); await navigate('overview'); } catch(e) { error = e.message; loading = false; } });
</script>

<svelte:head><title>{title} · School workspace</title></svelte:head>
<a class="skip" href="#main">Skip to content</a>
{#if meta}
<div class="workspace">
    <aside class:expanded={menu}>
        <a class="brand" href="/dashboard"><span class="mark">S</span><span>{meta.settings.school_name || 'School System'}<small>A little clarity, every day.</small></span></a>
        <nav aria-label="School tools">
            <button class:active={section === 'overview'} onclick={() => navigate('overview')}>Overview</button>
            {#if meta.canManage}<button class:active={section === 'people'} onclick={() => navigate('people')}>People & access</button>{/if}
            {#each meta.modules as item}<button class:active={section === item.key} data-cy={`nav-${item.key}`} onclick={() => navigate(item.key)}>{item.label}</button>{/each}
            {#if meta.canManage}<button class:active={section === 'audit'} onclick={() => navigate('audit')}>Activity history</button>{/if}
            {#if meta.user.roles.includes('owner')}<button class:active={section === 'settings'} onclick={() => navigate('settings')}>School settings</button>{/if}
        </nav>
    </aside>
    <div class="content">
        <header><button class="mobile-toggle" aria-expanded={menu} onclick={() => menu = !menu}>☰ Menu</button><span class="role">{meta.user.roles.join(' · ')}</span><div class="account"><a href="/account">Account</a><form action="/logout" method="post"><input type="hidden" name="_token" value={csrf}><button>Sign out</button></form></div></header>
        <main id="main">
            <div class="heading"><div><p class="eyebrow">YOUR SCHOOL WORKSPACE</p><h1>{title}</h1><p>{pageHelp ?? `Welcome, ${meta.user.name}. Let's make today a good school day.`}</p></div>{#if section !== 'audit'}<Button color="alternative" onclick={() => {step = 0; guide = true;}}>Page guide</Button>{/if}</div>
            {#if error}<div class="alert error" role="alert">{error}<button onclick={loadRows}>Try again</button></div>{/if}
            {#if message}<div class="alert success" role="status">{message}</div>{/if}
            {#if loading}<div class="empty"><Spinner /> <p>Loading your workspace…</p></div>
            {:else if section === 'overview'}
                <section class="welcome"><p class="eyebrow">A CLEAR START</p><h2>Everything you need.<br>One calm workspace.</h2><p>Choose a tool below to get started. Your information is shared according to your role and school assignments.</p>{#if meta.canManage}<Button onclick={() => navigate('people')}>Invite your school team</Button>{/if}</section>
                {#if meta.canManage}<div class="setup"><h2>Set up your school in order</h2><p>1. Add an academic year, classes and subjects. 2. Invite teachers, students and parents. 3. Create student records and link accounts. 4. Assign teachers and connect guardians. 5. Begin attendance, lessons and fees.</p></div>{/if}
                <div class="tiles">{#each meta.modules as item}<button class="tile" onclick={() => navigate(item.key)}><span class="tile-icon">{item.label.slice(0, 1)}</span><h2>{item.label}</h2><p>{item.help}</p><span class="open">Open tool →</span></button>{/each}</div>
            {:else if definition}
                <div class="toolbar"><form onsubmit={(e) => {e.preventDefault(); page = 1; loadRows();}}><label class="sr-only" for="search">Search records</label><input id="search" bind:value={search} placeholder="Search name or reference…" maxlength="100"><Button type="submit" color="alternative">Search</Button></form>{#if definition.canWrite}<Button onclick={() => openEditor()} data-cy="add-record">Add {definition.singular}</Button>{/if}</div>
                {#if section === 'attendance' && definition.canWrite}<div class="actions"><label for="attendance-class">Class</label><select id="attendance-class" bind:value={attendanceClass}><option value="">Choose a class</option>{#each meta.options.classes ?? [] as option}<option value={option.value}>{option.name}</option>{/each}</select><Button color="alternative" disabled={busy || !attendanceClass} onclick={openAttendance}>Quick attendance</Button></div>{/if}<p class="count">{total} {total === 1 ? 'record' : 'records'}</p>
                {#if !rows.length}<div class="empty"><h2>No records to show yet</h2><p>{definition.canWrite ? `Use Add ${definition.singular} to create the first record. Linked records such as classes must be created first.` : 'Your school will share records here once your account is linked and information is available.'}</p></div>{/if}
                <div class="records">{#each rows as row}<article class="record" data-cy="record"><h2>{row.name ?? row.title ?? row.reference ?? `${definition.singular} #${row.id}`}</h2><dl>{#each definition.fields as field}<div><dt>{field.label}</dt><dd>{display(field, row[field.name])}</dd></div>{/each}{#if row.balance !== undefined}<div><dt>Outstanding balance</dt><dd>{display({type: 'money'}, row.balance)}</dd></div>{/if}{#if row.net !== undefined}<div><dt>Net pay</dt><dd>{display({type: 'money'}, row.net)}</dd></div>{/if}{#if row.percentage !== undefined}<div><dt>Percentage</dt><dd>{row.percentage}%</dd></div>{/if}</dl><div class="actions">{#if definition.canWrite && !definition.immutable}<Button color="alternative" onclick={() => openEditor(row)}>Edit</Button>{/if}{#if ['payments','invoices','payroll','grades'].includes(section)}<a href={`/reports/${section}/${row.id}`} target="_blank" rel="noopener">Print / save PDF</a>{/if}</div></article>{/each}</div>
            {:else if section === 'people'}
                <div class="setup"><h2>Access starts with an invitation</h2><p>Choose a person's role, share their private invitation link, then link their account to a student or teacher assignment. Parents only see students connected through Guardian links. Only the Owner can invite administrators.</p></div>
                <Button onclick={() => {draft = {name:'',email:'',roles:['teacher']}; errors = {}; inviteUrl = ''; invite = true;}}>Invite person</Button> <Button color="alternative" onclick={() => navigate('invitations')}>Manage invitations</Button>
                <div class="records people">{#each rows as person}<article class="record"><h2>{person.name}</h2><p>{person.username || person.email}</p><p>{person.roles?.join(' · ')} · {person.is_active ? 'Active' : 'Suspended'}</p>{#if person.id !== meta.user.id && !person.roles?.includes('owner') && (meta.user.roles.includes('owner') || !person.roles?.includes('admin'))}<Button color="alternative" onclick={() => {personToEdit = person; selectedRoles = [...person.roles]; errors = {}; access = true;}}>Edit roles</Button> <Button color="alternative" disabled={busy} onclick={() => updateAccess(person)}>{person.is_active ? 'Suspend access' : 'Restore access'}</Button>{/if}</article>{/each}</div>
            {:else if section === 'invitations'}
                <div class="setup"><h2>Keep invitation links under your control</h2><p>Revoke an unused link if it was shared with the wrong person. Expired or revoked links cannot create accounts. To send a new link, return to People & access and invite the same email again. Accepted accounts are managed in People & access.</p></div>
                <Button color="alternative" onclick={() => navigate('people')}>Back to people</Button>
                <div class="records people">{#each rows as invitation}<article class="record" data-cy="invitation"><h2>{invitation.name}</h2><p>{invitation.email}</p><p>{invitation.roles.join(' · ')}</p><p>{invitation.is_pending ? 'Awaiting acceptance' : 'Expired or revoked'}</p>{#if invitation.is_pending}<Button color="alternative" disabled={busy} onclick={() => revokeInvitation(invitation)}>Revoke invitation</Button>{/if}</article>{/each}</div>
                {#if !rows.length}<p class="empty">No outstanding invitations.</p>{/if}
            {:else if section === 'settings'}
                <form class="settings record" onsubmit={saveSettings}><label for="school_name">School name *</label><input id="school_name" bind:value={meta.settings.school_name} required maxlength="150"><label for="currency">Currency code *</label><input id="currency" bind:value={meta.settings.currency} placeholder="For example PKR" pattern={'[A-Z]{3}'} required><label for="timezone">Timezone *</label><input id="timezone" bind:value={meta.settings.timezone} placeholder="For example Asia/Karachi" required><p>These settings identify your school. Payment entries record payments received outside this application.</p><Button type="submit" disabled={busy}>Save settings</Button></form>
            {:else if section === 'audit'}<div class="records">{#each rows as row}<article class="record"><h2>{row.action.replaceAll('_',' ')}</h2><p>{row.name ?? 'Former account'} · {row.module.replaceAll('_',' ')} #{row.record_id}</p><p>{row.created_at}</p></article>{/each}</div>{/if}
            {#if total > (definition ? 20 : 30)}<div class="pagination"><Button color="alternative" disabled={page === 1 || loading} onclick={() => {page--; loadRows();}}>Previous</Button><span>Page {page}</span><Button color="alternative" disabled={page * (definition ? 20 : 30) >= total || loading} onclick={() => {page++; loadRows();}}>Next</Button></div>{/if}
        </main><footer>School System · Built around your school day</footer>
    </div>
</div>
<Modal bind:open={guide} title={steps[step].title} size="md" dismissable={false}><p class="guide-step">STEP {step + 1} OF {steps.length}</p><p class="guide-copy">{steps[step].body}</p><div class="actions"><Button color="alternative" onclick={finishGuide}>Skip guide</Button>{#if step > 0}<Button color="alternative" onclick={() => step--}>Back</Button>{/if}<Button onclick={() => step < steps.length - 1 ? step++ : finishGuide()}>{step < steps.length - 1 ? 'Next tip' : 'Got it'}</Button></div></Modal>
<Modal bind:open={editor} title={`${editing ? 'Edit' : 'Add'} ${definition?.singular ?? 'record'}`} size="lg">
    <form onsubmit={saveRecord} class="record-form">{#if errors.form}<p role="alert" class="error">{errors.form[0]}</p>{/if}{#each definition?.fields ?? [] as field}<div class="field"><label for={`field-${field.name}`}>{field.label}{field.optional ? ' (optional)' : ' *'}</label>{#if field.type === 'relation' || field.type === 'select'}<select id={`field-${field.name}`} bind:value={draft[field.name]} required={!field.optional}><option value="">Choose {field.label.toLowerCase()}</option>{#each field.type === 'relation' ? (meta.options[field.relation] ?? []) : field.choices.map(v => ({value:v,name:v.replaceAll('_',' ')})) as option}<option value={option.value}>{option.name}</option>{/each}</select>{:else if field.type === 'textarea'}<textarea id={`field-${field.name}`} bind:value={draft[field.name]} required={!field.optional} maxlength="5000" rows="3"></textarea>{:else}<input id={`field-${field.name}`} type={['date','time','month','number'].includes(field.type) ? field.type : 'text'} inputmode={['money','decimal'].includes(field.type) ? 'decimal' : undefined} bind:value={draft[field.name]} required={!field.optional} min={field.min} max={field.max} maxlength="255">{/if}{#if errors[field.name]}<p class="error" role="alert">{errors[field.name][0]}</p>{/if}</div>{/each}<div class="actions"><Button color="alternative" onclick={() => editor = false} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save record'}</Button></div></form>
</Modal>
<Modal bind:open={attendanceSheet} title="Today's attendance" size="lg"><form class="record-form" onsubmit={saveAttendance}><p>{meta.today} · Choose a status for each student you want to record. Unselected students stay unchanged. Existing attendance for today will be updated.</p>{#if errors.form}<p class="error" role="alert">{errors.form[0]}</p>{/if}{#each attendanceRecords as record}<label for={`attendance-${record.student_id}`}>{record.name}</label><select id={`attendance-${record.student_id}`} bind:value={record.status}><option value="">Leave unchanged</option><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select>{/each}<Button type="submit" disabled={busy || !attendanceRecords.some(r => r.status)}>Save attendance</Button></form></Modal><Modal bind:open={access} title="Edit account roles"><form class="record-form" onsubmit={saveAccess}><p>Update access for {personToEdit?.name}. Verify their identity before assigning roles. Existing sessions will be revoked when you save.</p>{#if errors.form}<p class="error" role="alert">{errors.form[0]}</p>{/if}<fieldset><legend>Roles</legend>{#each ['teacher','parent','student','accountant', ...(meta.user.roles.includes('owner') ? ['admin'] : [])] as role}<label class="check"><input type="checkbox" value={role} bind:group={selectedRoles}>{role}</label>{/each}</fieldset><Button type="submit" disabled={busy}>Save roles</Button></form></Modal><Modal bind:open={invite} title="Invite a person"><form class="record-form" onsubmit={createInvite}>{#if errors.form}<p role="alert" class="error">{Object.values(errors).flat().join(' ')}</p>{/if}{#if inviteUrl}<p>Share this private link directly with the invited person. It expires in 48 hours and can be used once.</p><label for="invitation-link">Invitation link</label><input id="invitation-link" readonly value={inviteUrl} onclick={(e) => e.target.select()}>{:else}<label for="invite-name">Full name *</label><input id="invite-name" bind:value={draft.name} required><label for="invite-email">Email *</label><input id="invite-email" type="email" bind:value={draft.email} required><fieldset><legend>Roles *</legend>{#each ['teacher','parent','student','accountant', ...(meta.user.roles.includes('owner') ? ['admin'] : [])] as role}<label class="check"><input type="checkbox" value={role} bind:group={draft.roles}>{role}</label>{/each}</fieldset><Button type="submit" disabled={busy}>Create private invitation</Button>{/if}</form></Modal>
{:else}<main class="empty">{#if error}<p role="alert">{error}</p><a href="/dashboard">Reload workspace</a>{:else}<Spinner /><p>Opening your school workspace…</p>{/if}</main>{/if}

<style>
    :global(body){margin:0;background:#f7f8f3;color:#302b29;font-family:Arial,sans-serif} :global(button),:global(input),:global(select),:global(textarea){font:inherit} :global(button),:global(a){touch-action:manipulation} :global(button){cursor:pointer;min-height:44px} :global(input:not([type=checkbox]):not([type=hidden])),:global(select),:global(textarea){width:100%;border:1px solid #878e88;border-radius:9px;padding:11px 12px;background:white;color:#302b29;min-height:44px} :global(input[type=checkbox]){width:20px;height:20px} :global(:focus-visible){outline:3px solid #725752;outline-offset:3px} :global(label),:global(legend){font-weight:600;font-size:.9rem} :global(dialog){max-height:90dvh} .workspace{display:flex;min-height:100dvh}aside{width:245px;flex-shrink:0;background:#e8eee0;padding:24px 15px;position:sticky;top:0;height:100dvh;overflow:auto}.brand{display:flex;align-items:center;gap:10px;color:#302b29;font-weight:700;text-decoration:none;padding:0 5px 25px}.brand small{display:block;font-size:.65rem;font-weight:400;margin-top:5px}.mark{background:#725752;color:#fef6c9;border-radius:12px;padding:10px 14px;font-size:1.5rem}nav{display:grid;gap:3px}nav button{text-align:left;padding:10px 13px;border-radius:8px;font-size:.9rem}nav button:hover{background:#d4dfc7}nav button.active{background:#725752;color:white}.content{flex:1;min-width:0}header{display:flex;align-items:center;justify-content:space-between;padding:16px 30px;border-bottom:1px solid #d4dfc7;gap:10px;background:#fff}.role{background:#fef6c9;border-radius:20px;padding:7px 14px;font-size:.8rem;text-transform:capitalize}.account{display:flex;align-items:center;gap:20px;font-size:.9rem}main{max-width:1320px;padding:36px;margin:auto}.heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:26px}.eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.16em;color:#725752;margin-bottom:10px}h1{font-size:clamp(1.5rem,3vw,2.1rem);letter-spacing:-.035em;line-height:1.2;margin-bottom:10px}h2{font-weight:600;font-size:1.08rem}p{line-height:1.65}.heading p:not(.eyebrow){max-width:650px;font-size:.9rem;color:#565b55}.welcome{background:#d4dfc7;border-radius:20px;padding:35px;margin-bottom:24px}.welcome h2{font-size:clamp(1.8rem,4vw,2.7rem);line-height:1.15;letter-spacing:-.04em}.welcome p:not(.eyebrow){max-width:570px;margin:20px 0}.setup{background:#fef6c9;padding:22px;border-radius:14px;margin-bottom:24px}.setup p{font-size:.9rem;margin-top:8px}.tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.tile{display:flex;flex-direction:column;align-items:flex-start;text-align:left;background:white;border:1px solid #d4dfc7;border-radius:16px;padding:22px;gap:12px}.tile:hover{border-color:#725752}.tile p{font-size:.83rem;color:#565b55;flex:1}.tile-icon{background:#96c0b7;padding:7px 13px;border-radius:10px}.open{font-size:.85rem;font-weight:600;color:#725752}.toolbar{display:flex;justify-content:space-between;gap:15px}.toolbar form{display:flex;gap:8px;max-width:500px;flex:1}.count{margin:16px 0;color:#565b55;font-size:.85rem}.records{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.record{background:white;border:1px solid #d4dfc7;border-radius:14px;padding:24px;overflow-wrap:anywhere}.record h2{margin-bottom:15px}dl>div{display:grid;grid-template-columns:1fr 1.25fr;gap:16px;padding:8px 0;border-bottom:1px solid #edf0e8;font-size:.85rem}dt{color:#565b55}dd{white-space:pre-wrap}.actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:22px}.actions a{color:#725752;text-decoration:underline;font-size:.85rem;min-height:44px;display:flex;align-items:center}.record-form,.field,.settings{display:grid;gap:9px}.record-form{gap:17px}.settings{max-width:600px}.check{display:flex;align-items:center;gap:12px;min-height:44px;text-transform:capitalize}.people{margin-top:20px}.empty{padding:50px 20px;text-align:center}.empty p{max-width:520px;margin:15px auto}.alert{padding:16px;border-radius:10px;margin:12px 0}.error{color:#8a2020;background:#fff0ed;padding:8px;border-radius:6px}.success{background:#d4dfc7}.alert button{margin-left:16px;text-decoration:underline}.pagination{display:flex;justify-content:center;align-items:center;gap:20px;margin-top:25px}footer{padding:28px;text-align:center;font-size:.75rem;color:#565b55}.guide-copy{line-height:1.8}.guide-step{font-size:.7rem;letter-spacing:.1em;color:#725752;margin-bottom:12px}.mobile-toggle{display:none}.skip{position:absolute;left:15px;top:-100px;background:white;padding:10px;z-index:100}.skip:focus{top:10px}@media(max-width:1100px){.tiles{grid-template-columns:repeat(2,minmax(0,1fr))}aside{width:215px}main{padding:25px}}@media(max-width:760px){.workspace{display:block}aside{display:none;position:fixed;top:72px;left:0;height:calc(100dvh - 72px);width:min(310px,90vw);z-index:30;box-shadow:8px 8px 25px #302b2929}aside.expanded{display:block}.mobile-toggle{display:block}header{padding:10px 15px;position:sticky;top:0;z-index:35;min-height:72px}.account{gap:12px;font-size:.8rem}.role{display:none}main{padding:24px 16px}.heading{align-items:flex-start;flex-direction:column;gap:12px}.welcome{padding:26px}.tiles,.records{grid-template-columns:1fr}.toolbar{flex-direction:column}.toolbar form{max-width:none}.record{padding:20px}.setup{padding:18px}}
</style>
