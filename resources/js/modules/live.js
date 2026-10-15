import { http, refreshParts } from './ajax';

let refreshing = false;
async function refreshVisibleData() {
    if (refreshing || document.visibilityState !== 'visible' || ! navigator.onLine) return;
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
setInterval(refreshVisibleData, 15000);
document.addEventListener('visibilitychange', refreshVisibleData);
