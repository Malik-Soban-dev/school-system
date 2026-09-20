import { mount } from 'svelte';
import Preferences from './Preferences.svelte';
import { preferences, translate } from './preferences.js';
import '../css/glass.css';
const controls = document.getElementById('appearance-controls');
if (controls) mount(Preferences, { target: controls });
preferences.subscribe(({ language }) => {
    document.querySelectorAll('[data-i18n]').forEach(element => { element.textContent = translate(element.dataset.i18n, language); });
});
const form = document.querySelector('[data-login-form]');
if (form) form.addEventListener('submit', async event => {
    event.preventDefault();
    if (form.getAttribute('aria-busy') === 'true') return;
    const button = form.querySelector('[type=submit]');
    const progress = document.getElementById('login-progress');
    const error = document.getElementById('login-error');
    form.setAttribute('aria-busy', 'true'); button.disabled = true; progress.hidden = false; error.hidden = true;
    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.ok && response.url) {
                location.assign(response.url);
                return;
            }
            throw new Error(`The server returned an unexpected response (${response.status}). Please try again.`);
        }
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Unable to sign in. Please try again.');
        const destination = new URL(data.redirect || '/dashboard', location.origin);
        if (destination.origin !== location.origin) throw new Error('Unable to sign in. Please try again.');
        location.assign(destination.href);
    } catch (failure) {
        error.textContent = translate(failure.message, window.schoolPreferences.language);
        error.hidden = false; error.focus();
        form.setAttribute('aria-busy', 'false'); button.disabled = false; progress.hidden = true;
    }
});
