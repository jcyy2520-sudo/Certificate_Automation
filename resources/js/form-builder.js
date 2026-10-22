export function initFormBuilder() {

        var root = document.querySelector('[data-questions]');
        if (!root) return;
        var tabs = root.querySelectorAll('[data-question-tabs] input[name="qtab"]');
        var panels = root.querySelectorAll('[data-qpanel]');
        function sync() {
            var active = 'builder';
            tabs.forEach(function (t) { if (t.checked) active = t.value; });
            panels.forEach(function (p) { p.classList.toggle('hidden', p.getAttribute('data-qpanel') !== active); });
        }
        tabs.forEach(function (t) { t.addEventListener('change', sync); });
        sync();

}
