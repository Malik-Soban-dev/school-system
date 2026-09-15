<script>
import { preferences, setPreference } from './preferences.js';
</script>
<div class="preference-controls" aria-label={$preferences.language === 'ur' ? 'زبان اور ظاہری انداز' : 'Language and appearance'}>
<button type="button" data-cy="language-toggle" onclick={() => setPreference('language', $preferences.language === 'en' ? 'ur' : 'en')} aria-label={$preferences.language === 'en' ? 'Switch to Urdu' : 'Switch to English'}><span lang={$preferences.language === 'en' ? 'ur' : 'en'}>{$preferences.language === 'en' ? 'اردو' : 'English'}</span></button>
<button type="button" data-cy="theme-toggle" aria-pressed={$preferences.theme === 'dark'} onclick={() => setPreference('theme', $preferences.theme === 'light' ? 'dark' : 'light')} aria-label={$preferences.language === 'ur' ? 'روشن یا تاریک انداز' : 'Toggle light and dark mode'}>{$preferences.theme === 'dark' ? '☀' : '☾'} <span>{$preferences.language === 'ur' ? ($preferences.theme === 'dark' ? 'روشن' : 'تاریک') : ($preferences.theme === 'dark' ? 'Light' : 'Dark')}</span></button>
</div>
