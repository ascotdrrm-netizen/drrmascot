import './bootstrap';
import './modules/ajax';
import './modules/serverless-work';
import './modules/live';
import './modules/report-queue';
import './modules/locate';
import './modules/report-wizard';

document.addEventListener('ajax:success', function (event) {
    const form = event.target;
    const key = form.querySelector?.('[name="client_key"]');
    if (key && form.hasAttribute('data-ajax-reset')) {
        const next = window.crypto?.randomUUID ? window.crypto.randomUUID()
            : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
        key.value = key.defaultValue = next;
    }
});
import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import collapse from '@alpinejs/collapse';

Alpine.plugin(focus);
Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();

// Mark required fields consistently: any label whose control has the
// `required` attribute gets .is-required (a red asterisk via CSS).
function markRequiredLabels() {
    document.querySelectorAll('label[for]').forEach(function (label) {
        var control = document.getElementById(label.htmlFor);
        if (control && control.required) label.classList.add('is-required');
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', markRequiredLabels);
} else {
    markRequiredLabels();
}

// Dark / light theme (<x-theme-toggle>). The initial theme is set in <head>
// by layouts/partials/theme.blade.php so the page never flashes.
function applyThemeLabels() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
        var label = button.querySelector('[data-theme-label]');
        if (label) label.textContent = dark ? 'Light theme' : 'Dark theme';
    });
}

document.addEventListener('click', function (e) {
    if (! e.target.closest('[data-theme-toggle]')) return;
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('drrms-theme', next); } catch (err) { /* private mode: not remembered */ }
    applyThemeLabels();
    document.dispatchEvent(new CustomEvent('drrms:theme', { detail: { theme: next } }));
});

applyThemeLabels();

// Phone tables: give every cell its column's header as data-label, which the
// stacked-card layout in app.css shows beside the value (< 640px).
function labelTableCells() {
    document.querySelectorAll('table.data-table:not([data-no-stack])').forEach(function (table) {
        // The header's own words only, not an info tip's hidden explanation.
        var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
            var copy = th.cloneNode(true);
            copy.querySelectorAll('[data-info-tip], .sr-only, [hidden]').forEach(function (el) { el.remove(); });
            return copy.textContent.replace(/\s+/g, ' ').trim();
        });
        table.querySelectorAll('tbody tr').forEach(function (row) {
            Array.prototype.forEach.call(row.children, function (cell, i) {
                if (cell.tagName === 'TD' && ! cell.hasAttribute('colspan') && ! cell.hasAttribute('data-label')) {
                    cell.setAttribute('data-label', heads[i] || '');
                }
            });
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', labelTableCells);
} else {
    labelTableCells();
}

// Phone fields ([data-digits]) keep only digits and a leading +, as typed or pasted.
document.addEventListener('input', function (event) {
    const field = event.target;
    if (! field.matches || ! field.matches('[data-digits]')) return;
    const clean = field.value.replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '');
    if (clean !== field.value) field.value = clean;
});

// After an AJAX swap (ajax.js refreshParts / lists): the new rows and
// fields get the same treatment as on page load.
document.addEventListener('drrms:content-replaced', function () {
    labelTableCells();
    markRequiredLabels();
    applyThemeLabels();
});
