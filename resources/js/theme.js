export function initTheme() {
    const sync = () => {
        const dark = document.documentElement.classList.contains('dark');
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.setAttribute('aria-pressed', String(dark)));
    };
    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-theme-toggle]')) return;
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (_) { /* Preference is optional in private browsing. */ }
        sync();
    });
    window.addEventListener('storage', (event) => {
        if (event.key !== 'theme') return;
        document.documentElement.classList.toggle('dark', event.newValue === 'dark'); sync();
    });
    document.addEventListener('app:navigated', sync);
    sync();
}
