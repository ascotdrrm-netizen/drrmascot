document.addEventListener('alpine:init', () => {
    window.Alpine.data('reportWizard', (opts) => ({
        steps: ['What', 'Where', 'Details', 'Send'], step: 0, categories: opts.categories,
        type: opts.preset, place: '', coords: null, gpsText: 'GPS is optional; you can describe the place.',
        description: '', injured: null, photos: [], proof: null, sent: false, queued: false, sending: false,
        draftStatus: '', error: '', ready: false, saveTimer: null,
        clientKey: window.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }),
        async init() {
            if (this.categories.some((c) => c.key === opts.preset)) this.step = 1;
            if (! opts.live) return;
            const queue = window.drrmsReportQueue.current();
            try {
                let draft = await queue.loadDraft();
                if (opts.queuedKey) {
                    const queued = await queue.get(opts.queuedKey);
                    if (queued?.state === 'attention' && queued.campusId === queue.owner.campusId) draft = draft?.client_key === opts.queuedKey ? draft : queued.payload;
                    else this.error = 'That report is unavailable for editing. Check Your reports for its latest state.';
                }
                if (draft) this.restore(draft);
                this.ready = true;
                ['type', 'place', 'coords', 'description', 'injured', 'photos', 'proof', 'step'].forEach((field) => this.$watch(field, () => this.scheduleSave()));
            } catch (_) { this.ready = true; this.draftStatus = 'Device storage is unavailable. Keep this page open until your report is sent.'; }
        },
        restore(draft) {
            this.clientKey = draft.client_key;
            this.type = draft.category; this.place = draft.place || ''; this.description = draft.description || '';
            this.injured = draft.people_injured ?? null;
            this.coords = draft.latitude === null || draft.latitude === undefined ? null : { lat: draft.latitude, lng: draft.longitude, accuracy: draft.accuracy_m ?? null };
            this.photos = (draft.photos || []).map((p) => ({ ...p, url: URL.createObjectURL(p.file) }));
            this.proof = draft.proof ? new File([draft.proof.file], draft.proof.name, { type: draft.proof.file.type }) : null;
            this.step = Math.min(3, Math.max(0, draft.step || 0));
            this.draftStatus = 'Restored your saved draft, including its files.';
        },
        payload() {
            return {
                client_key: this.clientKey, category: this.type, place: this.place, description: this.description,
                people_injured: this.injured, latitude: this.coords?.lat ?? null, longitude: this.coords?.lng ?? null, accuracy_m: this.coords?.accuracy ?? null, step: this.step,
                photos: this.photos.map((p) => ({ file: p.file, name: p.name, caption: p.caption || '' })),
                proof: this.proof ? { file: this.proof, name: this.proof.name || 'proof' } : null,
            };
        },
        scheduleSave() {
            if (! opts.live || ! this.ready || this.sent || this.sending) return;
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(async () => {
                try { await window.drrmsReportQueue.current().saveDraft(this.payload()); this.draftStatus = 'Draft saved on this device.'; }
                catch (_) { this.draftStatus = 'Could not save this draft on the device. Keep this page open or free some storage.'; }
            }, 500);
        },
        get categoryLabel() { return this.categories.find((c) => c.key === this.type)?.label || ''; },
        next() { if (this.step < 3) this.step++; window.scrollTo(0, 0); },
        back() { if (this.step > 0) this.step--; },
        locate() {
            if (! navigator.geolocation) { this.gpsText = 'GPS is unavailable. Describe the place below.'; return; }
            this.gpsText = 'Finding you…';
            navigator.geolocation.getCurrentPosition((p) => {
                this.coords = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) };
                this.gpsText = 'Location found, accurate to about ' + Math.round(p.coords.accuracy) + ' m.';
            }, () => { this.gpsText = 'Location was not shared. Describe the place below.'; }, { enableHighAccuracy: true, timeout: 10000 });
        },
        addPhoto(event) {
            const file = event.target.files[0];
            if (file && this.photos.length < 3) this.photos.push({ file, name: file.name, caption: '', url: URL.createObjectURL(file) });
            event.target.value = '';
        },
        removePhoto(i) { URL.revokeObjectURL(this.photos[i].url); this.photos.splice(i, 1); },
        async send() {
            if (this.sending) return;
            this.error = '';
            if (! this.type || (! this.coords && ! this.place.trim()) || this.description.trim().length < 10) {
                this.error = 'Choose a type, share or describe the location, and give at least 10 characters describing what happened.'; return;
            }
            if (this.photos.some((p) => p.file.size > 10 * 1024 * 1024) || (this.proof && this.proof.size > 10 * 1024 * 1024)) {
                this.error = 'Each photo or proof file must be 10 MB or smaller.'; return;
            }
            if (! opts.live) { this.sent = true; return; }
            clearTimeout(this.saveTimer); this.sending = true;
            try {
                const result = await window.drrmsReportQueue.submit(this.payload());
                if (result.state === 'attention') { this.error = result.error; this.scheduleSave(); return; }
                this.queued = result.state !== 'sent';
                this.sent = true;
            } catch (error) {
                this.error = error.response?.data?.message || error.message || 'Not saved. Keep this page open and try again.';
            } finally { this.sending = false; if (! this.sent) this.scheduleSave(); }
        },
        destroy() { clearTimeout(this.saveTimer); this.photos.forEach((p) => URL.revokeObjectURL(p.url)); },
    }));
});
