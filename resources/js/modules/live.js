import { http, refreshParts } from './ajax';

// Each refresh re-renders part of the page on the server, so it runs only
// while someone is looking: the tab is visible, online, and used in the last
// IDLE_MS. A page left open overnight stops asking; the next touch, key or
// scroll catches up at once. (The SOS Console keeps its own faster check.)
const EVERY_MS = 30000;
const IDLE_MS = 5 * 60 * 1000;
let lastInput = Date.now();
let refreshing = false;
async function refreshVisibleData() {
    if (refreshing || document.visibilityState !== 'visible' || ! navigator.onLine) return;
    if (Date.now() - lastInput > IDLE_MS) return;
    refreshing = true;
    try {
        const bell = document.querySelector('[data-notification-bell]');
        if (bell) {
            const { data } = await http.get(bell.dataset.countUrl);
            document.querySelectorAll('[data-notification-bell]').forEach((item) => {
                const count = item.querySelector('[data-notification-count]');
                if (Number(count.textContent) !== data.unread) item.querySelector('[data-notification-announcement]').textContent = data.unread + ' unread notifications';
                count.textContent = data.unread;
                count.hidden = data.unread === 0;
                count.style.display = data.unread === 0 ? 'none' : '';
            });
        }
        const targets = Array.from(document.querySelectorAll('[data-live-refresh][id]'));
        if (targets.length && ! document.querySelector('form[aria-busy="true"]')) {
            await refreshParts(targets.map((target) => '#' + target.id));
        }
    } catch (error) {
        if (error.response?.status === 401) window.location.replace('/login');
    } finally { refreshing = false; }
}
setInterval(refreshVisibleData, EVERY_MS);
document.addEventListener('visibilitychange', refreshVisibleData);
['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((type) => window.addEventListener(type, () => {
    const wasIdle = Date.now() - lastInput > IDLE_MS;
    lastInput = Date.now();
    if (wasIdle) refreshVisibleData();
}, { passive: true }));
