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

        // ---- In-app toasts (replace every browser alert) --------------------
        // Trusted Types forbids innerHTML, so toast nodes are built element by
        // element with textContent. window.notify(type, message, options) is the
        // shared entry point used across the admin surface.
        var iconPaths = {
            'check-circle': ['M12 12m-8.5 0a8.5 8.5 0 1 0 17 0a8.5 8.5 0 1 0 -17 0', 'm8.5 12 2.4 2.4 4.6-4.8'],
            'alert': ['M12 12m-8.5 0a8.5 8.5 0 1 0 17 0a8.5 8.5 0 1 0 -17 0', 'M12 8v4.5M12 16h.01'],
            'info': ['M12 12m-8.5 0a8.5 8.5 0 1 0 17 0a8.5 8.5 0 1 0 -17 0', 'M12 11v5M12 8h.01'],
            'x': ['M18 6 6 18M6 6l12 12'],
        };
        function svgIcon(name) {
            var svgNS = 'http://www.w3.org/2000/svg';
            var svg = document.createElementNS(svgNS, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '1.6');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('class', 'size-full');
            (iconPaths[name] || iconPaths.info).forEach(function (d) {
                var path = document.createElementNS(svgNS, 'path');
                path.setAttribute('d', d);
                svg.appendChild(path);
            });
            return svg;
        }

        function viewport() {
            var vp = document.querySelector('[data-toast-viewport]');
            if (!vp) {
                vp = document.createElement('div');
                vp.className = 'toast-viewport';
                vp.setAttribute('data-toast-viewport', '');
                vp.setAttribute('aria-live', 'polite');
                document.body.appendChild(vp);
            }
            return vp;
        }

        function dismissToast(toast) {
            if (!toast || toast.getAttribute('data-leaving') === 'true') return;
            toast.setAttribute('data-leaving', 'true');
            window.setTimeout(function () { toast.remove(); }, 180);
        }

        function activateToast(toast) {
            var closer = toast.querySelector('[data-toast-close]');
            if (closer) closer.addEventListener('click', function () { dismissToast(toast); });
            var autohide = parseInt(toast.getAttribute('data-toast-autohide') || '0', 10);
            if (autohide > 0) window.setTimeout(function () { dismissToast(toast); }, autohide);
        }

        window.notify = function (type, message, options) {
            options = options || {};
            var iconName = type === 'success' ? 'check-circle' : (type === 'error' ? 'alert' : 'info');
            var toast = document.createElement('div');
            toast.className = 'toast toast-' + (type === 'success' ? 'success' : (type === 'error' ? 'error' : 'info'));
            toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
            toast.setAttribute('data-toast', '');

            var iconSpan = document.createElement('span');
            iconSpan.className = 'toast-icon';
            iconSpan.appendChild(svgIcon(iconName));
            toast.appendChild(iconSpan);

            var body = document.createElement('div');
            body.className = 'toast-body';
            if (options.title) {
                var title = document.createElement('p');
                title.className = 'toast-title';
                title.textContent = options.title;
                body.appendChild(title);
            }
            var text = document.createElement('p');
            text.className = options.title ? 'mt-0.5 text-slate-600' : '';
            text.textContent = message;
            body.appendChild(text);
            toast.appendChild(body);

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'toast-close';
            close.setAttribute('data-toast-close', '');
            close.setAttribute('aria-label', 'Dismiss');
            close.appendChild(svgIcon('x'));
            toast.appendChild(close);

            var hold = options.autohide === false ? 0 : (options.autohide || (type === 'error' ? 0 : 5000));
            if (hold > 0) toast.setAttribute('data-toast-autohide', String(hold));

            viewport().appendChild(toast);
            activateToast(toast);
            return toast;
        };

        // Wire any server-rendered flash toast already in the page.
        document.querySelectorAll('[data-toast-viewport] [data-toast]').forEach(activateToast);

        // ---- Confirmation modal (replaces window.confirm) -------------------
        var confirmModal = document.querySelector('[data-confirm-modal]');
        var pendingForm = null;
        var pendingSubmitter = null;
        var lastFocused = null;

        function closeConfirm() {
            if (!confirmModal) return;
            confirmModal.classList.add('hidden');
            confirmModal.setAttribute('hidden', '');
            pendingForm = null;
            pendingSubmitter = null;
            if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        }

        function openConfirm(form, message, submitter) {
            if (!confirmModal) { // Fallback keeps destructive actions usable if markup is missing.
                if (window.confirm(message)) { form.setAttribute('data-confirmed', '1'); form.requestSubmit(submitter || undefined); }
                return;
            }
            pendingForm = form;
            pendingSubmitter = submitter || null;
            lastFocused = document.activeElement;

            var tone = form.getAttribute('data-confirm-tone') || 'danger';
            var title = form.getAttribute('data-confirm-title') || 'Please confirm';
            var acceptLabel = form.getAttribute('data-confirm-action') || 'Confirm';

            confirmModal.querySelector('[data-confirm-title]').textContent = title;
            confirmModal.querySelector('[data-confirm-message]').textContent = message;

            var iconWrap = confirmModal.querySelector('[data-confirm-icon]');
            var accept = confirmModal.querySelector('[data-confirm-accept]');
            if (tone === 'danger') {
                iconWrap.className = 'modal-icon bg-red-50 text-red-600';
                accept.className = 'button-danger-solid sm:min-w-24';
            } else {
                iconWrap.className = 'modal-icon bg-accent-50 text-accent-600';
                accept.className = 'button-primary sm:min-w-24';
            }
            accept.textContent = acceptLabel;

            confirmModal.classList.remove('hidden');
            confirmModal.removeAttribute('hidden');
            accept.focus();
        }

        if (confirmModal) {
            confirmModal.querySelector('[data-confirm-cancel]').addEventListener('click', closeConfirm);
            confirmModal.querySelector('[data-confirm-accept]').addEventListener('click', function () {
                var form = pendingForm, submitter = pendingSubmitter;
                closeConfirm();
                if (form) { form.setAttribute('data-confirmed', '1'); showNavigationProgress(); form.requestSubmit(submitter || undefined); }
            });
            confirmModal.addEventListener('click', function (event) {
                if (event.target === confirmModal) closeConfirm();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !confirmModal.classList.contains('hidden')) closeConfirm();
            });
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            var confirmation = form.getAttribute('data-confirm');
            if (confirmation && !form.hasAttribute('data-confirmed')) {
                event.preventDefault();
                openConfirm(form, confirmation, event.submitter);
                return;
            }
            form.removeAttribute('data-confirmed');

            if (!event.defaultPrevented && (!form.target || form.target === '_self')) {
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
                // A checkbox toggles between the literal values "on" and "off";
                // a select/other control matches against its current value.
                var sourceValue = source.type === 'checkbox' ? (source.checked ? 'on' : 'off') : source.value;
                form.querySelectorAll('[data-visible-for]').forEach(function (el) {
                    var allowed = el.getAttribute('data-visible-for').split(',');
                    var visible = allowed.indexOf(sourceValue) !== -1;
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
