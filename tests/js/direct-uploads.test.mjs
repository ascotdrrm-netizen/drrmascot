import test from 'node:test';
import assert from 'node:assert/strict';
import { prepareDirectUploads, putPrivateUpload } from '../../resources/js/modules/direct-uploads.js';

test('files become scoped tickets, arrays retain their indexes, and original offline data survives', async () => {
    const original = new FormData();
    original.append('_token', 'csrf');
    original.append('photos[]', new File(['first'], 'first.jpg'));
    original.append('photos[]', new File(['second'], 'second.jpg'));
    original.append('proof', new File(['proof'], 'proof.pdf'));
    original.append('caption[]', 'Water at the entrance');
    const signed = [], sent = [];
    const result = await prepareDirectUploads(original, { target: '/app/reports/sync', method: 'POST',
        sign: async (data) => { signed.push(data); return { url: 'https://storage.test', headers: {}, ticket: data.field }; },
        put: async (url, headers, file) => sent.push(file.name),
    });
    assert.deepEqual(signed.map((data) => data.field), ['photos.0', 'photos.1', 'proof']);
    assert.ok(signed.every((data) => data.target === '/app/reports/sync' && data.method === 'POST'));
    assert.deepEqual(sent, ['first.jpg', 'second.jpg', 'proof.pdf']);
    assert.deepEqual(result.getAll('_staged_uploads[]'), ['photos.0', 'photos.1', 'proof']);
    assert.equal(result.get('_token'), 'csrf');
    assert.equal(result.get('caption[]'), 'Water at the entrance');
    assert.ok(![...result.values()].some((value) => value instanceof Blob));
    assert.equal(original.getAll('photos[]').length, 2);
});

test('storage receives no app cookies or forbidden headers and failures prevent record submission', async () => {
    const fetchOriginal = globalThis.fetch;
    try {
        globalThis.fetch = async (url, options) => {
            assert.equal(options.credentials, 'omit');
            assert.deepEqual(options.headers, { 'Content-Type': 'application/octet-stream' });
            return { ok: false, status: 413 };
        };
        await assert.rejects(putPrivateUpload('https://storage.test', { Host: ['storage.test'], 'Content-Length': ['4'], 'Content-Type': 'application/octet-stream' }, new File(['test'], 'file.pdf')),
            (error) => error.response.status === 422);
    } finally { globalThis.fetch = fetchOriginal; }
});
