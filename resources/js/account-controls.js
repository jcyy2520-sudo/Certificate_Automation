export function initAccountControls(signal) {
document.querySelectorAll('input[type="password"]').forEach(function (input, index) {
    input.id ||= 'password-control-' + index;
    if ([...document.querySelectorAll('[data-password-toggle]')].some(function (button) { return button.dataset.passwordToggle === input.id; })) return;
    var label = input.closest('label');
    if (!label) return;
    var wrapper = document.createElement('div'); wrapper.className = 'password-control';
    label.before(wrapper); wrapper.append(label);
    var button = document.createElement('button'); button.type = 'button'; button.className = 'password-reveal';
    button.dataset.passwordToggle = input.id; button.textContent = 'Show'; button.setAttribute('aria-label', 'Show password'); button.setAttribute('aria-pressed', 'false');
    wrapper.append(button); input.classList.add('password-input');
});

document.querySelectorAll('[data-account-link]').forEach(function (form) {
    var fragment = new URLSearchParams(window.location.hash.slice(1));
    form.querySelectorAll('[data-account-link-value]').forEach(function (input) { input.value = fragment.get(input.dataset.accountLinkValue) || ''; });
    if (!form.querySelector('[name="token"]').value) {
        var hint = document.createElement('p');
        hint.className = 'text-sm text-red-700 dark:text-red-300';
        hint.setAttribute('role', 'alert');
        hint.textContent = 'Open the complete link from your email. If it has expired, request a new link from the sign-in page.';
        form.prepend(hint);
    }
    // On validation redirects the form still needs its token. Keep it in the
    // fragment (never a query string or local storage), not in the Referer.
});
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    var input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    var reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.textContent = reveal ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(reveal));
    button.setAttribute('aria-label', (reveal ? 'Hide ' : 'Show ') + (input.labels?.[0]?.textContent.trim().toLowerCase() || 'password'));
}, { signal });
document.querySelectorAll('[data-password-option]').forEach(function (checkbox) {
    var fields = document.getElementById(checkbox.dataset.passwordOption);
    function update() { fields.hidden = !checkbox.checked; fields.querySelectorAll('input').forEach(function (input) { input.disabled = !checkbox.checked; }); }
    checkbox.addEventListener('change', update); update();
});
var invalid = document.querySelector('[aria-invalid="true"]');
if (invalid) invalid.focus();


}
