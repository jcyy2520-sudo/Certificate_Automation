export function initParticipantMessages(root = document, signal) {
    const composer = root.querySelector('[data-message-composer]');
    if (!composer) return;
    const recipients = [...composer.querySelectorAll('[data-message-recipient]')];
    const selectPage = composer.querySelector('[data-message-select-page]');
    const update = () => {
        const count = recipients.filter(input => input.checked).length;
        composer.querySelector('[data-message-count]').textContent = String(count);
        composer.querySelector('[data-message-submit]').disabled = count === 0;
        selectPage.checked = count > 0 && count === recipients.length;
        selectPage.indeterminate = count > 0 && count < recipients.length;
        composer.dataset.confirm = `Send this message to ${count} selected recipients? No certificate will be attached.`;
    };
    selectPage.addEventListener('change', () => {
        recipients.forEach(input => { input.checked = selectPage.checked; });
        update();
    }, { signal });
    recipients.forEach(input => input.addEventListener('change', update, { signal }));
    update();
}
