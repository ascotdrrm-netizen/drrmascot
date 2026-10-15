import { http } from './ajax';

let busy = false;
async function work() {
    const endpoint = document.querySelector('meta[name="broadcast-work-url"]')?.content;
    if (!endpoint || busy || document.visibilityState !== 'visible' || !navigator.onLine) return;
    busy = true;
    try {
        await http.post(endpoint, {}, { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content }, timeout: 45000 });
    } catch (_) { /* Delivery history retains Pending/Unknown, never claims success. */ }
    finally { busy = false; }
}
document.addEventListener('DOMContentLoaded', work);
document.addEventListener('visibilitychange', work);
setInterval(work, 15000);
