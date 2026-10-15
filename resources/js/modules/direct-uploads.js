// Keep the normal Laravel file validation by sending private upload tickets
// instead of file bytes through Vercel. The offline queue still owns the blobs.
export async function prepareDirectUploads(data, { sign, put, target, method, onProgress }) {
    const result = new FormData();
    const counters = new Map();
    const files = [];
    for (const [name, value] of data.entries()) {
        if (!(value instanceof Blob)) { result.append(name, value); continue; }
        if (!value.size && !value.name) continue; // Empty native file inputs.
        const index = counters.get(name) || 0;
        counters.set(name, index + 1);
        const field = name.replace(/\[([^\]]*)\]/g, (_, part) => '.' + (part || index));
        files.push({ field, value });
    }
    for (let i = 0; i < files.length; i++) {
        const { field, value } = files[i];
        const signed = await sign({ name: value.name || 'upload.bin', size: value.size, field, target, method });
        await put(signed.url, signed.headers, value);
        result.append('_staged_uploads[]', signed.ticket);
        onProgress?.(Math.round((i + 1) / files.length * 100));
    }
    return result;
}

export async function putPrivateUpload(url, headers, file, signal) {
    // Browsers supply these transport headers themselves. Laravel checks the
    // actual object size against the ticket. Never send app cookies or CSRF.
    const allowed = Object.fromEntries(Object.entries(headers).filter(([name]) => !['host', 'content-length'].includes(name.toLowerCase())));
    const response = await fetch(url, { method: 'PUT', headers: allowed, body: file, credentials: 'omit', signal });
    if (!response.ok) {
        const error = new Error('The file could not be uploaded. Please retry.');
        error.response = { status: response.status === 413 ? 422 : 503, data: { message: error.message } };
        throw error;
    }
}
