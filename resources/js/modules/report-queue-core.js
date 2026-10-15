// Storage and HTTP are injected so queue ownership, leases and retries can be
// tested without a browser. The browser adapter uses IndexedDB transactions.
export function createReportQueue({ store, owner, send, online = () => true, now = Date.now, token = () => Math.random().toString(36), changed = () => {} }) {
    const prefix = `report:${owner.id}:`;
    const lease = token();
    const leaseMs = 180000;
    let running = false;
    const keyFor = (clientKey) => prefix + clientKey;
    const belongs = (item) => item && item.ownerId === owner.id && item.campusId === owner.campusId;
    const stateError = (message) => Object.assign(new Error(message), { code: 'QUEUE_STATE' });

    async function list() {
        return (await store.entries()).filter(([key, item]) => String(key).startsWith(prefix) && item?.ownerId === owner.id)
            .map(([, item]) => item).sort((a, b) => a.savedAt - b.savedAt);
    }

    async function enqueue(payload) {
        if (await store.get(`receipt:${owner.id}:${payload.client_key}`)) throw stateError('This report has already been sent. Start a new report.');
        const key = keyFor(payload.client_key);
        let accepted = false;
        await store.update(key, (existing) => {
            if (existing && ! belongs(existing)) throw stateError('This report was saved for another campus. Open Your reports to review it.');
            if (existing?.state === 'sending' && existing.leaseUntil > now()) return existing;
            accepted = true;
            return { payload, ownerId: owner.id, campusId: owner.campusId, state: 'pending',
                savedAt: existing?.savedAt || now(), attempts: 0, retryAt: 0, error: '', leaseUntil: 0 };
        });
        if (! accepted) throw stateError('This report is already uploading. Wait for its result before changing it.');
        changed();
    }

    async function sync() {
        if (running || ! online()) return [];
        running = true;
        const results = [];
        try {
            for (const item of await list()) {
                if (! belongs(item) || ! online()) continue;
                const key = keyFor(item.payload.client_key);
                let claimed = null;
                await store.update(key, (current) => {
                    if (! belongs(current) || ['attention', 'auth'].includes(current.state) || current.retryAt > now()
                        || (current.state === 'sending' && current.leaseUntil > now())) return current;
                    claimed = { ...current, state: 'sending', lease, leaseUntil: now() + leaseMs, attempts: current.attempts + 1 };
                    return claimed;
                });
                if (! claimed) continue;
                changed();
                try {
                    const response = await send(claimed.payload, owner);
                    if (! Number.isInteger(response.id) || response.id < 1) throw new Error('The server did not confirm a saved report.');
                    const receiptKey = `receipt:${owner.id}:${item.payload.client_key}`;
                    const receipt = { id: response.id, sentAt: now() };
                    try { await store.set(receiptKey, receipt); }
                    catch (_) {
                        // The server has acknowledged it. Free the binary copy if quota cannot fit even a receipt.
                        await store.delete(key);
                        try { await store.set(receiptKey, receipt); } catch (_) {}
                    }
                    await store.delete(key);
                    results.push({ clientKey: item.payload.client_key, id: response.id, state: 'sent' });
                } catch (error) {
                    const status = error.response?.status;
                    const state = [401, 403, 409, 419].includes(status) ? 'auth'
                        : (status >= 400 && status < 500 && ! [408, 429].includes(status) ? 'attention' : 'pending');
                    const message = error.response?.data?.message || 'Waiting for a connection. This report is still saved on this device.';
                    await store.update(key, (current) => current?.lease === lease ? {
                        ...current, state, leaseUntil: 0, error: message,
                        retryAt: state === 'pending' ? now() + Math.min(300000, 15000 * 2 ** Math.min(current.attempts - 1, 5)) : 0,
                    } : current);
                    results.push({ clientKey: item.payload.client_key, state, error: message });
                    if (state === 'auth') break;
                } finally { changed(); }
            }
        } finally { running = false; }
        return results;
    }

    async function retry(clientKey) {
        if (! belongs(await store.get(keyFor(clientKey)))) return [];
        await store.update(keyFor(clientKey), (item) => belongs(item) && item.state !== 'attention'
            && ! (item.state === 'sending' && item.leaseUntil > now()) ? { ...item, state: 'pending', retryAt: 0 } : item);
        return sync();
    }

    async function remove(clientKey) {
        const key = keyFor(clientKey);
        // Use one IndexedDB transaction so another tab cannot claim between checking and removing.
        await store.update(key, (item) => {
            if (item?.state === 'sending' && item.leaseUntil > now()) throw stateError('Wait for this upload to finish before removing it.');
            return undefined;
        });
        changed();
    }

    return { enqueue, sync, retry, remove, list, get: (clientKey) => store.get(keyFor(clientKey)) };
}

export function reportFormData(payload, owner, Form = FormData) {
    const data = new Form();
    data.append('owner_id', owner.id);
    data.append('owner_campus_id', owner.campusId);
    for (const name of ['client_key', 'category', 'place', 'description', 'people_injured', 'latitude', 'longitude', 'accuracy_m']) {
        if (payload[name] !== null && payload[name] !== undefined && payload[name] !== '') data.append(name, payload[name]);
    }
    (payload.photos || []).forEach((photo, i) => {
        data.append(`photos[${i}]`, photo.file, photo.name);
        data.append(`captions[${i}]`, photo.caption || '');
    });
    if (payload.proof) data.append('proof', payload.proof.file, payload.proof.name);
    return data;
}
