/** Existing server validation owns the messages, for async and native forms. */
export function initFormFeedback() {
    document.querySelectorAll('form').forEach((form) => { form.noValidate = true; });
    document.querySelectorAll('input, select, textarea').forEach((field) => {
        if (field.type === 'hidden') return;
        const errors = [...(field.parentElement?.querySelectorAll('.field-error, span.text-red-600, p.text-red-600, p.text-red-700') || [])];
        const error = errors.find((node) => node.textContent.trim() && node.textContent.trim() !== '*');
        if (!error) return;
        error.id ||= `field-error-${Math.random().toString(36).slice(2)}`;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', [...new Set([...(field.getAttribute('aria-describedby') || '').split(' '), error.id])].filter(Boolean).join(' '));
    });
}
