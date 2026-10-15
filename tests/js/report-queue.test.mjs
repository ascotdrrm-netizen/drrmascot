import test from 'node:test';
import assert from 'node:assert/strict';
import { createReportQueue, reportFormData } from '../../resources/js/modules/report-queue-core.js';

function memoryStore() {
    const data = new Map();
    return {
        async get(key) { return structuredClone(data.get(key)); },
        async set(key, value) { data.set(key, structuredClone(value)); },
        async delete(key) { data.delete(key); },
        async entries() { return structuredClone([...data.entries()]); },
        async update(key, fn) { data.set(key, structuredClone(fn(structuredClone(data.get(key))))); },
    };
}
const owner = { id: 12, campusId: 3 };
const payload = () => ({ client_key: '2b348fe5-0785-46a8-9b12-1bc065639f70', category: 'flood',
    place: 'Library', description: 'Water is rising outside the library.', people_injured: 0,
    photos: [{ file: new Blob(['PHOTO'], { type: 'image/jpeg' }), name: 'flood.jpg', caption: 'Entrance flooding' }],
    proof: { file: new Blob(['PDF'], { type: 'application/pdf' }), name: 'proof.pdf' } });

test('an offline report keeps binary files and sends their names, captions and ownership after reconnecting', async () => {
    const store = memoryStore(); let online = false; let requests = 0;
    const queue = createReportQueue({ store, owner, online: () => online, send: async (data, user) => {
        requests++;
        const form = reportFormData(data, user);
        assert.equal(form.get('owner_id'), '12');
        assert.equal(form.get('owner_campus_id'), '3');
        assert.equal(form.get('photos[0]').name, 'flood.jpg');
        assert.equal(await form.get('photos[0]').text(), 'PHOTO');
        assert.equal(form.get('captions[0]'), 'Entrance flooding');
        assert.equal(form.get('proof').name, 'proof.pdf');
        assert.equal(await form.get('proof').text(), 'PDF');
        return { id: 31 };
    } });
    await queue.enqueue(payload()); await queue.sync();
    assert.equal(requests, 0); assert.equal((await queue.list()).length, 1);
    online = true; assert.equal((await queue.sync())[0].id, 31);
    assert.equal((await queue.list()).length, 0);
    assert.deepEqual((await store.get(`receipt:12:${payload().client_key}`)).id, 31);
    await assert.rejects(queue.enqueue(payload()), /already been sent/);
});

test('a lost acknowledgement retries the same key and preserves the original file', async () => {
    const store = memoryStore(); let time = 1000; const server = new Map(); let calls = 0;
    const queue = createReportQueue({ store, owner, now: () => time, send: async (data) => {
        calls++; server.set(data.client_key, 45);
        assert.equal(await data.photos[0].file.text(), 'PHOTO');
        if (calls === 1) throw new Error('Connection lost after server saved the report');
        return { id: server.get(data.client_key) };
    } });
    await queue.enqueue(payload()); await queue.sync();
    assert.equal((await queue.list())[0].state, 'pending');
    await queue.sync(); assert.equal(calls, 1, 'automatic retry respects the backoff');
    time += 16000; await queue.sync();
    assert.equal(calls, 2); assert.equal(server.size, 1); assert.equal((await queue.list()).length, 0);
});

test('two tabs cannot upload the same leased report at once', async () => {
    const store = memoryStore(); let calls = 0; let release;
    const barrier = new Promise((resolve) => { release = resolve; });
    const send = async () => { calls++; await barrier; return { id: 8 }; };
    const first = createReportQueue({ store, owner, send, token: () => 'first' });
    const second = createReportQueue({ store, owner, send, token: () => 'second' });
    await first.enqueue(payload());
    const run = first.sync();
    while (! calls) await new Promise((resolve) => setTimeout(resolve, 0));
    await second.sync(); assert.equal(calls, 1);
    await assert.rejects(second.remove(payload().client_key), /finish/);
    release(); await run;
});

test('a different account or campus cannot send an old queue and authentication errors pause retries', async () => {
    const store = memoryStore(); let calls = 0;
    const queue = createReportQueue({ store, owner, send: async () => { calls++; throw { response: { status: 409, data: { message: 'Wrong account' } } }; } });
    await queue.enqueue(payload()); await queue.sync(); await queue.sync();
    assert.equal(calls, 1); assert.equal((await queue.list())[0].state, 'auth');
    const other = createReportQueue({ store, owner: { id: 99, campusId: 3 }, send: () => assert.fail('Wrong account uploaded') });
    assert.equal((await other.list()).length, 0); await other.sync();
    const moved = createReportQueue({ store, owner: { ...owner, campusId: 4 }, send: () => assert.fail('Wrong campus uploaded') });
    await moved.sync(); assert.equal((await moved.list()).length, 1);
    await assert.rejects(moved.enqueue(payload()), { code: 'QUEUE_STATE' });
});

test('validation failures remain editable and never retry automatically; an expired crash lease can resume', async () => {
    const store = memoryStore(); let time = 1000; let valid = false; let calls = 0;
    const queue = createReportQueue({ store, owner, now: () => time, send: async () => {
        calls++; if (! valid) throw { response: { status: 422, data: { message: 'Description required' } } };
        return { id: 9 };
    } });
    await queue.enqueue(payload()); await queue.sync(); time += 999999;
    await queue.sync(); assert.equal(calls, 1); assert.equal((await queue.list())[0].state, 'attention');
    valid = true; await queue.enqueue(payload());
    await store.update(`report:12:${payload().client_key}`, (item) => ({ ...item, state: 'sending', lease: 'crashed-tab', leaseUntil: time - 1 }));
    await queue.sync(); assert.equal(calls, 2); assert.equal((await queue.list()).length, 0);
});

test('storage exhaustion never pretends that a report was queued', async () => {
    const store = memoryStore(); store.update = async () => { throw new DOMException('Full', 'QuotaExceededError'); };
    const queue = createReportQueue({ store, owner, send: () => assert.fail('Unsaved report uploaded') });
    await assert.rejects(queue.enqueue(payload()), { name: 'QuotaExceededError' });
    assert.equal((await queue.list()).length, 0);
});

test('a confirmed upload can release its files when the device has no room for its receipt', async () => {
    const store = memoryStore(); const originalSet = store.set;
    store.set = async (key, value) => {
        if (key.startsWith('receipt:') && (await store.entries()).some(([key, value]) => key.startsWith('report:') && value)) throw new DOMException('Full', 'QuotaExceededError');
        return originalSet(key, value);
    };
    const queue = createReportQueue({ store, owner, send: async () => ({ id: 91 }) });
    await queue.enqueue(payload());
    assert.equal((await queue.sync())[0].state, 'sent');
    assert.equal((await queue.list()).length, 0);
    assert.equal((await store.get(`receipt:12:${payload().client_key}`)).id, 91);
});
