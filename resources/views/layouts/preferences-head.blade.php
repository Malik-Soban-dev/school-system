<script>
(() => {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem('school-preferences') || '{}') || {}; } catch {}
    const language = saved.language === 'ur' ? 'ur' : 'en';
    const theme = ['light', 'dark'].includes(saved.theme) ? saved.theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.lang = language;
    document.documentElement.dir = language === 'ur' ? 'rtl' : 'ltr';
    document.documentElement.classList.toggle('dark', theme === 'dark');
    document.documentElement.style.colorScheme = theme;
    window.schoolPreferences = {language, theme};
})();
</script>
