/*
 * AJAX for the whole app (docs/PLAN.md B5). Every page still works without
 * JavaScript: these only take over what is marked.
 *
 *   <form data-ajax>            submit without a reload. Field errors appear
 *                               under each field; uploads show progress; a
 *                               dropped connection retries (the entries are
 *                               never lost). On success: a toast, then either
 *                               stay (data-ajax-stay or the server says so),
 *                               refresh parts of the page (data-ajax-refresh),
 *                               or go where the server says.
 *   <button data-ajax-action="url" data-method="post|patch|put|delete">
 *                               one-click actions (approve, restore, verify…),
 *                               with data-confirm / data-confirm-title,
 *                               data-ajax-remove="#row" and data-ajax-refresh.
 *   <div data-ajax-list="name"> a list whose filter form (GET) and pagination
 *                               links load in place, keeping the URL in step.
 *
 * The server side is App\Http\Middleware\AjaxResponses: for an AJAX request a
 * normal "redirect back with a message" becomes {message, redirect, stay} and
 * errors become {message, errors} with status 422 — so no controller needed
 * rewriting. Parts of the page are refreshed by fetching the page again and
 * swapping the matching elements (refreshParts), so the HTML always comes
 * from the same Blade views.
 */
import axios from 'axios';
import { prepareDirectUploads, putPrivateUpload } from './direct-uploads';

export const http = axios.create({
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    withCredentials: true,
});

http.interceptors.request.use(async (config) => {
    const endpoint = document.querySelector('meta[name="direct-upload-url"]')?.content;
    if (!endpoint || !(config.data instanceof FormData) || ![...config.data.values()].some((value) => value instanceof Blob)) return config;
    const target = new URL(config.url, window.location.href);
    if (target.origin !== window.location.origin) throw new Error('Uploads must be submitted to this site.');
    const method = String(config.data.get('_method') || config.method || 'POST').toUpperCase();
    const csrf = config.data.get('_token') || document.querySelector('meta[name="csrf-token"]')?.content;
    config.data = await prepareDirectUploads(config.data, {
        target: target.pathname, method,
        sign: async (data) => (await http.post(endpoint, data, { headers: { 'X-CSRF-TOKEN': csrf } })).data,
        put: (url, headers, file) => putPrivateUpload(url, headers, file, config.signal),
        onProgress: (percent) => config.onUploadProgress?.({ loaded: percent, total: 100 }),
    });
    return config;
});

// A page left open past the session (a phone app open all day) gets a 419:
// fetch a fresh CSRF cookie once and send the same request again.
http.interceptors.response.use(null, async (error) => {
    const config = error.config || {};
    if (error.response && error.response.status === 419 && ! config.__csrfRetried) {
        config.__csrfRetried = true;
        const refresh = await axios.get('/csrf-refresh', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const token = refresh.data.token;
        if (config.data instanceof FormData) config.data.set('_token', token);
        else if (config.data instanceof URLSearchParams) config.data.set('_token', token);
        else if (config.data && typeof config.data === 'object') config.data._token = token;
        config.headers = config.headers || {};
        config.headers['X-CSRF-TOKEN'] = token;
        return http(config);
    }
    return Promise.reject(error);
});

export function toast(message, tone = 'success') {
    if (! message) return;
    window.dispatchEvent(new CustomEvent('drrms:toast', { detail: { message, tone } }));
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** One key per submission (App\Http\Middleware\IdempotentRequests): a retry of it is never saved twice. */
export function idempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
}

/**
 * Sends a request; when there is no answer at all (offline), tries up to 3
 * more times. A change (anything but GET) carries one Idempotency-Key for all
 * its tries, so a try whose answer was lost on a slow connection is not saved
 * again: the server answers the retry with the first try's result (or 409
 * while the first try is still running, and then this waits and asks again).
 */
export async function send(config, { retries = 3, onRetry } = {}) {
    if ((config.method || 'get').toLowerCase() !== 'get') {
        config.headers = { 'Idempotency-Key': idempotencyKey(), ...(config.headers || {}) };
    }
    for (let attempt = 0, waits = 0; ; attempt++) {
        try {
            return await http(config);
        } catch (error) {
            if (error.response && error.response.status === 409 && error.response.data && error.response.data.pending && waits < 10) {
                waits++;
                attempt--;
                await sleep(1500);
                continue;
            }
            const noAnswer = ! error.response && error.code !== 'ERR_CANCELED';
            if (! noAnswer || attempt >= retries) throw error;
            if (onRetry) onRetry(attempt + 1, retries);
            await sleep(1000 * 2 ** attempt);
        }
    }
}

// ----------------------------------------------------------------- errors

function fieldsNamed(form, key) {
    // "photos.1" (Laravel) is "photos[1]" or the second "photos[]" in the form.
    const parts = key.split('.');
    const bracketed = parts[0] + parts.slice(1).map((p) => '[' + p + ']').join('');
    let found = form.querySelectorAll('[name="' + CSS.escape(bracketed) + '"]');
    if (! found.length && parts.length > 1) {
        const all = form.querySelectorAll('[name="' + CSS.escape(parts[0] + '[]') + '"]');
        const index = Number(parts[1]);
        if (all[index]) return [all[index]];
        if (all.length) return [all[0]];
    }
    if (! found.length) found = form.querySelectorAll('[name="' + CSS.escape(parts[0]) + '"]');
    return Array.from(found);
}

export function clearErrors(form) {
    form.querySelectorAll('[data-ajax-error]').forEach((el) => el.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
}

export function showErrors(form, errors) {
    clearErrors(form);
    let first = null;
    const unplaced = [];
    Object.entries(errors || {}).forEach(([key, messages]) => {
        const message = Array.isArray(messages) ? messages[0] : String(messages);
        const field = fieldsNamed(form, key)[0];
        if (! field) {
            unplaced.push(message);
            return;
        }
        const id = 'err-' + key.replace(/[^\w-]/g, '-');
        const note = document.createElement('p');
        note.className = 'form-error mt-1';
        note.id = id;
        note.setAttribute('data-ajax-error', '');
        note.textContent = message;
        // After the field's own wrapper when it is a radio/checkbox group.
        const anchor = field.closest('[data-field]') || (['radio', 'checkbox'].includes(field.type) ? field.closest('fieldset') || field.parentElement : field);
        anchor.insertAdjacentElement('afterend', note);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + id).trim());
        first = first || field;
    });
    if (unplaced.length) {
        const box = document.createElement('div');
        box.className = 'alert alert-danger mb-4';
        box.setAttribute('data-ajax-error', '');
        box.setAttribute('role', 'alert');
        box.textContent = unplaced.join(' ');
        form.prepend(box);
        first = first || box;
    }
    if (first) {
        first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (typeof first.focus === 'function') first.focus({ preventScroll: true });
    }
}

// ----------------------------------------------------------- busy buttons

function setBusy(button, busy, text) {
    if (! button) return;
    if (busy) {
        if (! button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML;
        button.setAttribute('aria-busy', 'true');
        if (text) button.textContent = text;
        setTimeout(() => { button.disabled = true; }, 0);
    } else {
        if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
        delete button.dataset.originalHtml;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.classList.remove('cursor-wait');
    }
}

// ------------------------------------------------------- upload progress

function progressBar(form) {
    let bar = form.querySelector('[data-upload-progress]');
    if (! bar) {
        bar = document.createElement('div');
        bar.setAttribute('data-upload-progress', '');
        bar.className = 'mt-4';
        bar.innerHTML = '<div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full bg-accent transition-[width]" style="width:0%" data-bar></div></div><p class="caption mt-1" data-text aria-live="polite"></p>';
        (form.querySelector('[type="submit"]')?.parentElement || form).insertAdjacentElement('beforebegin', bar);
    }
    bar.hidden = false;
    return {
        set(percent, text) {
            bar.querySelector('[data-bar]').style.width = percent + '%';
            bar.querySelector('[data-text]').textContent = text;
        },
        hide() { bar.hidden = true; },
    };
}

const hasFiles = (form) => Array.from(form.querySelectorAll('input[type="file"]')).some((input) => input.files && input.files.length);

// ------------------------------------------------------ refreshing parts

/** Fetches the current page again and swaps every element matching each selector. */
export async function refreshParts(selectors, url = window.location.href) {
    const list = (Array.isArray(selectors) ? selectors : String(selectors).split(',')).map((s) => s.trim()).filter(Boolean);
    if (! list.length) return;
    const response = await http.get(url, { headers: { Accept: 'text/html' } });
    const doc = new DOMParser().parseFromString(response.data, 'text/html');
    list.forEach((selector) => {
        const fresh = doc.querySelectorAll(selector);
        document.querySelectorAll(selector).forEach((old, i) => {
            if (fresh[i]) old.replaceWith(document.importNode(fresh[i], true));
        });
    });
    document.dispatchEvent(new CustomEvent('drrms:content-replaced'));
}

// ------------------------------------------------------------- outcomes

async function handleSuccess(data, element) {
    const stay = element.hasAttribute('data-ajax-stay') || data.stay;
    const refresh = element.getAttribute('data-ajax-refresh');
    const remove = element.getAttribute('data-ajax-remove');

    if (data.redirect && ! stay) {
        // Another page: the message travels with it (flashed by the server).
        window.location.assign(data.redirect);
        return;
    }
    toast(data.message || 'Saved.');
    if (remove) document.querySelectorAll(remove).forEach((el) => el.remove());
    if (refresh) await refreshParts(refresh);
    if (element.tagName === 'FORM' && element.hasAttribute('data-ajax-reset')) {
        element.reset();
        element.querySelectorAll('select').forEach((select) => select.dispatchEvent(new Event('change', { bubbles: true })));
        element.querySelector('[data-location-clear]')?.click();
    }
    element.dispatchEvent(new CustomEvent('ajax:success', { detail: data, bubbles: true }));
}

function handleFailure(error, form) {
    const response = error.response;
    if (response && response.status === 422) {
        if (form) showErrors(form, response.data.errors);
        toast(response.data.message && ! form ? response.data.message : 'Please check the highlighted fields.', 'danger');
        return;
    }
    // Signed out elsewhere (a password change ends other sign-ins): reloading
    // lets the server send this page to its own login screen.
    if (response && response.status === 401) {
        window.location.reload();
        return;
    }
    if (response && response.status === 429) {
        toast('Too many tries. Please wait a minute and try again.', 'danger');
        return;
    }
    if (response && response.status === 403) {
        toast('You are not allowed to do that.', 'danger');
        return;
    }
    if (response && response.status === 404) {
        toast('That record is no longer there. Refresh the page.', 'danger');
        return;
    }
    if (! response) {
        toast('No connection. Nothing was lost — tap the button again when you are back online.', 'danger');
        return;
    }
    toast((response.data && response.data.message) || 'Something went wrong on the server. Please try again.', 'danger');
}

// ---------------------------------------------------------------- forms

async function submitForm(form, submitter) {
    // A second tap or Enter while the first is still sending is ignored.
    if (form.getAttribute('aria-busy') === 'true') return;
    const method = (form.getAttribute('method') || 'post').toLowerCase();
    const data = new FormData(form);
    if (submitter && submitter.name) data.append(submitter.name, submitter.value);
    const uploading = hasFiles(form);
    const progress = uploading ? progressBar(form) : null;

    clearErrors(form);
    form.setAttribute('aria-busy', 'true');
    setBusy(submitter || form.querySelector('[type="submit"]'), true);
    try {
        const response = await send({
            method,
            url: form.action,
            data,
            onUploadProgress: progress ? (e) => {
                const percent = e.total ? Math.round((e.loaded / e.total) * 100) : 0;
                progress.set(percent, percent < 100 ? 'Uploading… ' + percent + '%' : 'Saving…');
            } : undefined,
        }, {
            onRetry: (n, of) => toast('No connection. Trying again (' + n + ' of ' + of + ')…', 'warning'),
        });
        await handleSuccess(response.data || {}, form);
    } catch (error) {
        handleFailure(error, form);
    } finally {
        form.removeAttribute('aria-busy');
        setBusy(submitter || form.querySelector('[type="submit"]'), false);
        if (progress) progress.hide();
    }
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (! form.matches || !(form.matches('form[data-ajax]') || (document.querySelector('meta[name="direct-upload-url"]') && form.enctype === 'multipart/form-data' && hasFiles(form)))) return;
    // The confirm dialog stops the first submit and sends it again once
    // confirmed (confirm-dialog.blade.php); only that second one goes here.
    if (e.defaultPrevented) return;
    e.preventDefault();
    submitForm(form, e.submitter);
});

// --------------------------------------------------------------- actions

async function runAction(button) {
    if (button.getAttribute('aria-busy') === 'true') return;
    const method = (button.dataset.method || 'post').toLowerCase();
    setBusy(button, true, button.dataset.loadingText);
    try {
        const response = await send({
            method: method === 'get' ? 'get' : 'post',
            url: button.dataset.ajaxAction,
            data: method === 'get' || method === 'post' ? (button.dataset.payload ? JSON.parse(button.dataset.payload) : {}) : { _method: method.toUpperCase(), ...(button.dataset.payload ? JSON.parse(button.dataset.payload) : {}) },
        });
        await handleSuccess(response.data || {}, button);
    } catch (error) {
        handleFailure(error, null);
    } finally {
        if (document.body.contains(button)) setBusy(button, false);
    }
}

document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-ajax-action]');
    if (! button || button.disabled) return;
    e.preventDefault();
    if (button.dataset.confirm && window.drrmsConfirm) {
        window.drrmsConfirm(button.dataset.confirm, () => runAction(button), {
            title: button.dataset.confirmTitle, button: button.dataset.confirmButton, focus: button.dataset.confirmFocus,
        });
        return;
    }
    runAction(button);
});

// ----------------------------------------------------------------- lists

async function loadList(list, url, push = true) {
    list.setAttribute('aria-busy', 'true');
    try {
        const response = await http.get(url, { headers: { Accept: 'text/html' } });
        const doc = new DOMParser().parseFromString(response.data, 'text/html');
        const fresh = doc.querySelector('[data-ajax-list="' + CSS.escape(list.dataset.ajaxList) + '"]');
        if (! fresh) {
            window.location.assign(url);
            return;
        }
        list.replaceWith(document.importNode(fresh, true));
        if (push) history.pushState({ ajaxList: true }, '', url);
        document.dispatchEvent(new CustomEvent('drrms:content-replaced'));
    } catch (error) {
        handleFailure(error, null);
        list.removeAttribute('aria-busy');
    }
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    const list = form.closest && form.closest('[data-ajax-list]');
    if (! list || (form.getAttribute('method') || 'get').toLowerCase() !== 'get' || e.defaultPrevented) return;
    e.preventDefault();
    const url = new URL(form.action || window.location.href, window.location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();
    loadList(list, url.toString());
});

document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-ajax-list] a[href]');
    if (! link || e.ctrlKey || e.metaKey || e.shiftKey || link.target === '_blank' || link.hasAttribute('data-no-ajax')) return;
    const list = link.closest('[data-ajax-list]');
    const url = new URL(link.href, window.location.href);
    // Only links that stay on this list (filters, sorting, pagination).
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
    e.preventDefault();
    loadList(list, url.toString());
});

window.addEventListener('popstate', () => {
    const list = document.querySelector('[data-ajax-list]');
    if (list) loadList(list, window.location.href, false);
});

window.drrmsAjax = { http, send, toast, refreshParts, showErrors, clearErrors };
