import { mount } from 'svelte';
import Portal from './Portal.svelte';
import '../css/app.css';
import '../css/glass.css';

mount(Portal, { target: document.getElementById('school-portal') });

// Keep the hosting instance warm while a signed-in workspace tab is open.
const heartbeatTimer = window.setInterval(() => {
    fetch('/up', { method: 'GET', cache: 'no-store', credentials: 'omit' }).catch(() => {});
}, 5 * 60 * 1000);

window.addEventListener('pagehide', () => window.clearInterval(heartbeatTimer), { once: true });
