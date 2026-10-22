export function initFormResponses(root = document, signal) {
    if (document.querySelector('[data-response-summary]')) {
        const timer = window.setInterval(() => {
            if (!document.hidden) window.webinarNavigation?.refresh();
        }, 30000);
        signal?.addEventListener('abort', () => clearInterval(timer), { once: true });
    }
}
