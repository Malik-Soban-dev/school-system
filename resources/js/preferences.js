import { writable, derived } from 'svelte/store';
import ur from './ur.json';
const initial = window.schoolPreferences || { language: 'en', theme: 'light' };
export const preferences = writable(initial);
export function translate(text, language = initial.language) {
    if (language !== 'ur' || typeof text !== 'string') return text;
    return ur[text] ?? ur[text.trim()] ?? text;
}
export const translator = derived(preferences, ($preferences) => (text) => translate(text, $preferences.language));
export function hydratePreferences(serverPreferences) {
    if (!serverPreferences || !['en', 'ur'].includes(serverPreferences.language) || !['light', 'dark', 'system'].includes(serverPreferences.theme)) return;
    const theme = serverPreferences.theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : serverPreferences.theme;
    applyPreferences({ language: serverPreferences.language, theme });
}
function applyPreferences(next) {
    document.documentElement.lang = next.language;
    document.documentElement.dir = next.language === 'ur' ? 'rtl' : 'ltr';
    document.documentElement.classList.toggle('dark', next.theme === 'dark');
    document.documentElement.style.colorScheme = next.theme;
    try { localStorage.setItem('school-preferences', JSON.stringify(next)); } catch {}
    window.schoolPreferences = next;
}
export function setPreference(key, value) {
    preferences.update(current => {
        const next = { ...current, [key]: value };
        applyPreferences(next);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf && location.pathname.startsWith('/dashboard')) {
            fetch('/portal/interface-preferences', {method: 'PUT', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({language: next.language, theme: next.theme})}).catch(() => {});
        }
        return next;
    });
}
