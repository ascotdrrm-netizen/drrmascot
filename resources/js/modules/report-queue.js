import { createStore, get, set, del, update, entries } from 'idb-keyval';
import { http, toast } from './ajax';
import { createReportQueue, reportFormData } from './report-queue-core';

const db = createStore('ascot-reporter', 'reports');
const store = { get: (key) => get(key, db), set: (key, value) => set(key, value, db),
    delete: (key) => del(key, db), entries: () => entries(db), update: (key, change) => update(key, change, db) };
let active = null;

function context() {
    const el = document.querySelector('[data-reporter-id]');
    if (! el) return null;
    if (active) return active;
    const owner = { id: Number(el.dataset.reporterId), campusId: Number(el.dataset.reporterCampus) };
    const changed = () => window.dispatchEvent(new CustomEvent('drrms:queue-changed'));
    const queue = createReportQueue({ store, owner, online: () => navigator.onLine,
        changed, token: () => window.crypto?.randomUUID?.() || String(Date.now()) + Math.random(),
        send: async (payload) => (await http.post(el.dataset.reportSyncUrl, reportFormData(payload, owner), { timeout: 120000 })).data,
    });
    const draftKey = `draft:${owner.id}`;
    active = { ...queue, owner,
        sendDirect: async (payload) => {
            const { data } = await http.post(el.dataset.reportSyncUrl, reportFormData(payload, owner), { timeout: 120000 });
            if (! Number.isInteger(data.id) || data.id < 1) throw new Error('The server did not confirm this report. Keep this page open and retry.');
            return { state: 'sent', id: data.id };
        },
        saveDraft: (payload) => store.set(draftKey, { payload, ownerId: owner.id, campusId: owner.campusId }),
        loadDraft: async () => {
            const draft = await store.get(draftKey);
            if (draft?.campusId !== owner.campusId) return null;
            const queued = await queue.get(draft.payload.client_key);
            if (await store.get(`receipt:${owner.id}:${draft.payload.client_key}`) || (queued && queued.state !== 'attention')) { await store.delete(draftKey); return null; }
            return draft.payload;
        },
        clearDraft: () => store.delete(draftKey),
    };
    return active;
}

window.drrmsReportQueue = {
    current: context,
    async submit(payload) {
        const queue = context();
        if (! queue) throw new Error('Sign in as a Reporter to save this report.');
        try { await queue.enqueue(payload); }
        catch (storageError) {
            if (storageError.code === 'QUEUE_STATE') throw storageError;
            if (! navigator.onLine) throw new Error('Not saved: device storage is unavailable or full. Keep this page open and reconnect to send.');
            // Online reporting still works when private browsing or quota limits block IndexedDB.
            const result = await queue.sendDirect(payload);
            try { await queue.clearDraft(); } catch (_) {}
            return result;
        }
        try { await queue.clearDraft(); } catch (_) { /* The queued copy already owns this draft's key. */ }
        const results = await queue.sync();
        const result = results.find((item) => item.clientKey === payload.client_key);
        return result || { state: (await queue.get(payload.client_key))?.state || 'pending' };
    },
};

async function sync() {
    try {
        const results = await context()?.sync();
        if (results?.some((item) => item.state === 'sent')) {
            toast('Saved reports reached the DRRM Office.');
            window.dispatchEvent(new CustomEvent('drrms:reports-synced'));
        }
    } catch (_) { /* Storage errors are shown when the reporter saves or opens their queue. */ }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pendingReports', () => ({
        items: [], error: '',
        async init() { await this.refresh(); },
        async refresh() {
            try { this.items = await context()?.list() || []; }
            catch (_) { this.error = 'This browser could not open the reports saved on this device.'; }
        },
        label(item) {
            if (item.campusId !== context()?.owner.campusId) return 'Saved for your previous campus; contact the DRRM Office';
            return { pending: 'Waiting to send', sending: 'Uploading', attention: 'Needs your review', auth: 'Sign in to resume' }[item.state];
        },
        async retry(item) { try { await context().retry(item.payload.client_key); await this.refresh(); } catch (_) { this.error = 'Could not retry. Your saved report is still on this device.'; } },
        async remove(item) {
            if (! window.confirm('Remove this unsent report and its files from this device?')) return;
            try { await context().remove(item.payload.client_key); await this.refresh(); }
            catch (error) { this.error = error.message; }
        },
    }));
});
window.addEventListener('online', sync);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') sync(); });
document.addEventListener('DOMContentLoaded', async () => {
    // A fresh authenticated page can resume reports paused by an expired session.
    try {
        const queue = context();
        if (queue) for (const item of await queue.list()) if (item.state === 'auth' && item.campusId === queue.owner.campusId) await queue.retry(item.payload.client_key);
    } catch (_) {}
    sync();
});
setInterval(() => { if (document.visibilityState === 'visible') sync(); }, 30000);
