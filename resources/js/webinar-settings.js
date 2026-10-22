export function initWebinarSettings() {

        // Keep the Open/Closed explanation in sync before settings are saved.
        var openSwitch = document.querySelector('[data-webinar-open-switch]');
        var openLabel = document.querySelector('[data-webinar-open-label]');
        var openHelp = document.querySelector('[data-webinar-open-help]');
        var openText = document.querySelector('[data-webinar-open-text]');
        if (openSwitch && openLabel && openHelp && openText) {
            var syncOpen = function () {
                var on = openSwitch.checked;
                openLabel.textContent = on ? 'Webinar open' : 'Webinar closed';
                openText.textContent = on
                    ? 'The webinar is open. Each form still follows its own Open/Closed switch and deadline.'
                    : 'The webinar is closed. No participant form can accept responses until you open it.';
                openHelp.classList.toggle('border-emerald-200', on);
                openHelp.classList.toggle('bg-emerald-50', on);
                openHelp.classList.toggle('text-emerald-900', on);
                openHelp.classList.toggle('border-slate-200', !on);
                openHelp.classList.toggle('bg-slate-50', !on);
                openHelp.classList.toggle('text-slate-600', !on);
            };
            openSwitch.addEventListener('change', syncOpen);
            syncOpen();
        }

        // Swap the plain-language explanation as the email-confirmation switch flips.
        var verifySwitch = document.querySelector('[data-verify-switch]');
        var verifyHelp = document.querySelector('[data-verify-help]');
        if (verifySwitch && verifyHelp) {
            var syncVerify = function () {
                var state = verifySwitch.checked ? 'on' : 'off';
                verifyHelp.querySelectorAll('[data-verify-help-for]').forEach(function (el) {
                    el.classList.toggle('hidden', el.getAttribute('data-verify-help-for') !== state);
                });
            };
            verifySwitch.addEventListener('change', syncVerify);
            syncVerify();
        }

}
