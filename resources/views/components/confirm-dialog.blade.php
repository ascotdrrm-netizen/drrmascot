{{--
    The ONE confirmation dialog in the app, included once by each layout
    (layouts.app and layouts.public). Opened by:

      <a data-confirm="message">          navigates to href on confirm
      <form data-confirm-form="message">  submits the form on confirm

    Optional attributes on either (used by Logout):
      data-confirm-title="Log out?"       heading; becomes the aria-labelledby
      data-confirm-button="Log out"       confirm button label (default "Confirm")
      data-confirm-focus="cancel"         focus Cancel instead of Confirm on open
      data-confirm-tone="danger"          red confirm button (deletes)

    Behaviour: centred over a dimmed page, Escape / backdrop click / Cancel
    close it, Tab stays on its two buttons, body scroll is locked while open
    (and restored — including when the mobile drawer had its own lock), focus
    returns to the control that opened it. The confirm path is a plain
    form.submit(), so the form's route, CSRF token and redirect are untouched.
--}}
@once
    <style>
        #confirm-modal:not(.hidden) { animation: drrms-confirm-fade 150ms ease-out both; }
        #confirm-modal:not(.hidden) [data-modal-panel] { animation: drrms-confirm-pop 150ms ease-out both; }
        @keyframes drrms-confirm-fade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes drrms-confirm-pop { from { opacity: 0; transform: translateY(4px) scale(.98); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            #confirm-modal:not(.hidden), #confirm-modal:not(.hidden) [data-modal-panel] { animation: none; }
        }
    </style>

    {{-- inset-0 + flex centring (no vh-based offsets); the panel scrolls on its own if a phone is very short. --}}
    <div id="confirm-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/50 p-4" data-modal-backdrop>
        <div class="max-h-[90%] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-6 shadow-xl" data-modal-panel
             role="dialog" aria-modal="true" aria-labelledby="confirm-modal-message">
            <h2 id="confirm-modal-title" class="section-title hidden"></h2>
            <p id="confirm-modal-message" class="mb-6 text-sm text-gray-700">Are you sure?</p>
            {{-- Same order visually and for Tab: Cancel first. --}}
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-end">
                <button type="button" id="confirm-modal-cancel"
                        class="btn btn-secondary">Cancel</button>
                <button type="button" id="confirm-modal-confirm"
                        class="btn btn-primary">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('confirm-modal');
            var panel = modal.querySelector('[data-modal-panel]');
            var titleEl = document.getElementById('confirm-modal-title');
            var messageEl = document.getElementById('confirm-modal-message');
            var confirmBtn = document.getElementById('confirm-modal-confirm');
            var cancelBtn = document.getElementById('confirm-modal-cancel');
            var pendingAction = null;
            var returnFocus = null;
            var savedOverflow = '';

            function isOpen() {
                return ! modal.classList.contains('hidden');
            }

            // The container is "hidden items-center justify-center": it needs
            // "flex" while open or the centering classes do nothing and the
            // dialog renders pinned to the top-left.
            function openModal(message, action, opts) {
                opts = opts || {};
                messageEl.textContent = message || 'Are you sure?';

                if (opts.title) {
                    titleEl.textContent = opts.title;
                    titleEl.classList.remove('hidden');
                    panel.setAttribute('aria-labelledby', 'confirm-modal-title');
                    panel.setAttribute('aria-describedby', 'confirm-modal-message');
                } else {
                    titleEl.classList.add('hidden');
                    panel.setAttribute('aria-labelledby', 'confirm-modal-message');
                    panel.removeAttribute('aria-describedby');
                }
                confirmBtn.textContent = opts.confirmLabel || 'Confirm';
                // Destructive actions (deletes) get a red confirm button.
                confirmBtn.classList.toggle('btn-primary', opts.tone !== 'danger');
                confirmBtn.classList.toggle('btn-danger-solid', opts.tone === 'danger');

                pendingAction = action;
                // The control that opened it (Safari doesn't focus buttons on click).
                returnFocus = opts.opener || document.activeElement;
                savedOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';

                modal.classList.remove('hidden');
                modal.classList.add('flex');
                (opts.focus === 'cancel' ? cancelBtn : confirmBtn).focus();
            }

            function closeModal() {
                if (! isOpen()) return;
                pendingAction = null;
                document.body.style.overflow = savedOverflow;

                var hide = function () {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                };
                if (window.drrmsMotion) {
                    window.drrmsMotion.afterCloseAnimation(modal, hide);
                } else {
                    hide();
                }

                if (returnFocus && document.contains(returnFocus) && returnFocus.focus) {
                    returnFocus.focus();
                }
            }

            function optionsFrom(el, opener) {
                return {
                    title: el.dataset.confirmTitle,
                    confirmLabel: el.dataset.confirmButton,
                    tone: el.dataset.confirmTone,
                    focus: el.dataset.confirmFocus,
                    opener: opener || el,
                };
            }

            confirmBtn.addEventListener('click', function () {
                var action = pendingAction;
                closeModal();
                if (action) action();
            });

            cancelBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });

            // Capture phase on window, so Escape closes only the dialog — not
            // also the mobile drawer behind it (whose listener is on document).
            window.addEventListener('keydown', function (e) {
                if (! isOpen()) return;

                if (e.key === 'Escape') {
                    e.stopPropagation();
                    closeModal();
                } else if (e.key === 'Tab') {
                    // Keep Tab on the dialog's two buttons while it's open.
                    var first = cancelBtn, last = confirmBtn;
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                    else if (! panel.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
                }
            }, true);

            document.addEventListener('click', function (e) {
                var link = e.target.closest('[data-confirm]');
                // AJAX action buttons open this dialog themselves (window.drrmsConfirm).
                if (link && ! link.hasAttribute('data-ajax-action')) {
                    e.preventDefault();
                    openModal(link.dataset.confirm, function () {
                        window.location.href = link.href;
                    }, optionsFrom(link));
                }
            });

            // For resources/js/modules/ajax.js: the same dialog for an AJAX action.
            window.drrmsConfirm = function (message, onConfirm, opts) {
                opts = opts || {};
                openModal(message, onConfirm, { title: opts.title, confirmLabel: opts.button, tone: opts.tone, focus: opts.focus });
            };

            // A plain form.submit() does NOT re-fire the 'submit' event, so no
            // re-entry guard is needed for an ordinary form. An AJAX form
            // (data-ajax) is sent again with requestSubmit(), which does fire
            // it: marked as confirmed, it passes straight through to ajax.js.
            document.addEventListener('submit', function (e) {
                var form = e.target.closest('[data-confirm-form]');
                if (form && form.dataset.confirmed === '1') {
                    delete form.dataset.confirmed;
                    return;
                }
                if (form) {
                    e.preventDefault();
                    var submitter = e.submitter;
                    openModal(form.dataset.confirmForm, function () {
                        if (form.hasAttribute('data-ajax') && form.requestSubmit) {
                            form.dataset.confirmed = '1';
                            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                        } else {
                            form.submit();
                        }
                    }, optionsFrom(form, submitter));
                }
            });
        })();
    </script>
@endonce
