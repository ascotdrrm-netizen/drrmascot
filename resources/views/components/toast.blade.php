{{--
    Short messages after an AJAX save, action or error (resources/js/modules/
    ajax.js dispatches "drrms:toast" with {message, tone}). One per layout.
    Read out by screen readers: polite for success, assertive for problems.
    Above the phone app's tab bar; bottom-right on a desktop.
--}}
<div x-data="{
        items: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message: detail.message, tone: detail.tone || 'success' });
            setTimeout(() => this.remove(id), detail.tone === 'danger' ? 8000 : 4000);
        },
        remove(id) { this.items = this.items.filter((t) => t.id !== id); },
     }"
     x-on:drrms:toast.window="add($event.detail)"
     class="pointer-events-none fixed inset-x-4 bottom-20 z-[80] flex flex-col items-center gap-2 sm:inset-x-auto sm:bottom-6 sm:right-6 sm:items-end"
     data-toasts>
    <div class="sr-only" aria-live="polite" x-text="items.filter(t => t.tone !== 'danger').map(t => t.message).join(' ')"></div>
    <div class="sr-only" aria-live="assertive" x-text="items.filter(t => t.tone === 'danger').map(t => t.message).join(' ')"></div>
    <template x-for="toast in items" :key="toast.id">
        <div x-transition.opacity.duration.150ms
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-none sm:w-96"
             :class="{
                'border-green-200 bg-green-50 text-green-800': toast.tone === 'success',
                'border-red-200 bg-red-50 text-red-800': toast.tone === 'danger',
                'border-amber-200 bg-amber-50 text-amber-800': toast.tone === 'warning',
                'border-blue-200 bg-blue-50 text-blue-800': toast.tone === 'info',
             }">
            <span class="mt-0.5 shrink-0" x-show="toast.tone === 'success'"><x-icon name="check_circle" fill class="h-5 w-5" /></span>
            <span class="mt-0.5 shrink-0" x-show="toast.tone !== 'success'"><x-icon name="warning" fill class="h-5 w-5" /></span>
            <p class="min-w-0 flex-1 leading-5" x-text="toast.message"></p>
            <button type="button" class="-mr-1 shrink-0 rounded p-1 opacity-70 hover:opacity-100" x-on:click="remove(toast.id)" aria-label="Dismiss">
                <x-icon name="close" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
