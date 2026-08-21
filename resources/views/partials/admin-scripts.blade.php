<script nonce="{{ $cspNonce }}">
    (function () {
        var root = document.documentElement;

        // Give immediate feedback while the next server-rendered page is loading.
        // The pageshow reset also handles pages restored instantly from the bfcache.
        var navigationReset;
        var clearNavigationProgress = function () {
            root.classList.remove('is-navigating');
            window.clearTimeout(navigationReset);
        };
        var showNavigationProgress = function () {
            root.classList.add('is-navigating');
            // Attachment responses (for example CSV exports) keep this page
            // alive, so never leave its cursor in a loading state indefinitely.
            window.clearTimeout(navigationReset);
            navigationReset = window.setTimeout(clearNavigationProgress, 8000);
        };

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            var link = event.target.closest('a[href]');
            if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

            var destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.href === window.location.href) return;
            if (destination.pathname === window.location.pathname && destination.search === window.location.search && destination.hash) return;

            showNavigationProgress();
        });

        document.addEventListener('submit', function (event) {
            var confirmation = event.target.getAttribute('data-confirm');
            if (confirmation && !window.confirm(confirmation)) {
                event.preventDefault();
                return;
            }

            if (!event.defaultPrevented && (!event.target.target || event.target.target === '_self')) {
                showNavigationProgress();
            }
        });

        document.addEventListener('change', function (event) {
            var control = event.target.closest('[data-auto-submit]');
            if (control && control.form) control.form.requestSubmit();
        });

        // A select marked data-toggles-visibility shows/hides any
        // data-visible-for="a,b" element in the same form, matched against
        // its current value — e.g. only showing "Choices" once the answer
        // type is actually multiple choice. Controls inside a hidden block
        // are also disabled, so a stale value (like leftover choice text
        // from before switching the type) never gets submitted alongside
        // whichever block is actually visible.
        var syncVisibilityToggles = function (form) {
            if (!form) return;
            form.querySelectorAll('[data-toggles-visibility]').forEach(function (source) {
                form.querySelectorAll('[data-visible-for]').forEach(function (el) {
                    var allowed = el.getAttribute('data-visible-for').split(',');
                    var visible = allowed.indexOf(source.value) !== -1;
                    el.classList.toggle('hidden', !visible);
                    el.querySelectorAll('input, textarea, select').forEach(function (control) {
                        control.disabled = !visible;
                    });
                });
            });
        };
        document.querySelectorAll('form').forEach(syncVisibilityToggles);
        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-toggles-visibility]')) syncVisibilityToggles(event.target.form);
        });

        // A dynamic list of choice rows: [data-add-choice] appends a row by
        // cloning the first one (fresh, unchecked, renumbered), and
        // [data-remove-choice] removes its row, never below two.
        document.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-choice]');
            if (addButton) {
                var rows = addButton.closest('[data-choice-field]').querySelector('[data-choice-rows]');
                var existing = rows.querySelectorAll('[data-choice-row]');
                var nextIndex = 0;
                existing.forEach(function (row) { nextIndex = Math.max(nextIndex, parseInt(row.dataset.index, 10) + 1); });

                var newRow = existing[0].cloneNode(true);
                newRow.dataset.index = nextIndex;
                var radio = newRow.querySelector('[data-choice-radio]');
                radio.value = nextIndex;
                radio.checked = false;
                var text = newRow.querySelector('[data-choice-text]');
                text.name = 'choices[' + nextIndex + ']';
                text.value = '';
                rows.appendChild(newRow);
                text.focus();
                return;
            }

            var removeButton = event.target.closest('[data-remove-choice]');
            if (removeButton) {
                var row = removeButton.closest('[data-choice-row]');
                var siblingRows = row.parentElement.querySelectorAll('[data-choice-row]');
                if (siblingRows.length > 2) row.remove();
            }
        });

        document.addEventListener('click', function (event) {
            var selectable = event.target.closest('[data-select-on-click]');
            if (selectable && typeof selectable.select === 'function') selectable.select();

            var copyButton = event.target.closest('[data-copy-target]');
            if (!copyButton) return;

            var source = document.querySelector(copyButton.getAttribute('data-copy-target'));
            if (!source) return;

            var copied = function () {
                var original = copyButton.getAttribute('data-copy-label') || copyButton.textContent;
                copyButton.textContent = copyButton.getAttribute('data-copy-success') || 'Copied';
                window.setTimeout(function () { copyButton.textContent = original; }, 1800);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(source.value).then(copied);
            } else {
                source.select();
                if (document.execCommand('copy')) copied();
            }
        });

        window.addEventListener('pageshow', function () {
            clearNavigationProgress();
        });

        document.querySelectorAll('[data-rail-toggle]').forEach(function (button) {
            var sync = function () {
                button.setAttribute('aria-expanded', root.classList.contains('rail-open') ? 'true' : 'false');
            };
            button.addEventListener('click', function () {
                var open = root.classList.toggle('rail-open');
                try { localStorage.setItem('rail', open ? 'open' : 'closed'); } catch (e) {}
                sync();
            });
            sync();
        });

        document.querySelectorAll('[data-webinar-nav-toggle]').forEach(function (button) {
            var sync = function () {
                var collapsed = root.classList.contains('webinar-nav-collapsed');
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                button.setAttribute('aria-label', collapsed ? 'Maximize webinar sidebar' : 'Minimize webinar sidebar');
                button.setAttribute('title', collapsed ? 'Maximize webinar sidebar' : 'Minimize webinar sidebar');
            };
            try {
                if (localStorage.getItem('webinar-nav') === 'collapsed') root.classList.add('webinar-nav-collapsed');
            } catch (e) {}
            button.addEventListener('click', function () {
                var collapsed = root.classList.toggle('webinar-nav-collapsed');
                try { localStorage.setItem('webinar-nav', collapsed ? 'collapsed' : 'open'); } catch (e) {}
                sync();
            });
            sync();
        });

        // Collapsible secondary-sidebar sections persist their open/closed
        // state per group id, so a chosen layout survives navigation.
        document.querySelectorAll('details[data-nav-group]').forEach(function (group) {
            var key = 'nav-group:' + group.getAttribute('data-nav-group');
            try {
                var stored = localStorage.getItem(key);
                if (stored === 'closed') group.open = false;
                if (stored === 'open') group.open = true;
            } catch (e) {}
            group.addEventListener('toggle', function () {
                try { localStorage.setItem(key, group.open ? 'open' : 'closed'); } catch (e) {}
            });
        });
    })();
</script>
