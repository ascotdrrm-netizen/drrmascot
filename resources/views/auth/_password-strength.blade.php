@php $for = $for ?? 'password'; @endphp

<div class="mt-4" data-strength-meter data-for="{{ $for }}">
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
        <div data-strength-bar class="h-full w-0 rounded-full bg-red-500 transition-all duration-300 ease-out"></div>
    </div>

    <ul class="mt-4 grid grid-cols-2 gap-x-4 text-xs leading-5">
        <li data-strength-req="length" class="flex items-center text-gray-600 transition-colors duration-200">
            <span data-strength-icon class="inline-block w-4 shrink-0 transition-transform duration-200">&#10007;</span>
            8+ characters
        </li>
        <li data-strength-req="uppercase" class="flex items-center text-gray-600 transition-colors duration-200">
            <span data-strength-icon class="inline-block w-4 shrink-0 transition-transform duration-200">&#10007;</span>
            Uppercase letter
        </li>
        <li data-strength-req="number" class="flex items-center text-gray-600 transition-colors duration-200">
            <span data-strength-icon class="inline-block w-4 shrink-0 transition-transform duration-200">&#10007;</span>
            Number
        </li>
        <li data-strength-req="symbol" class="flex items-center text-gray-600 transition-colors duration-200">
            <span data-strength-icon class="inline-block w-4 shrink-0 transition-transform duration-200">&#10007;</span>
            Symbol
        </li>
    </ul>
</div>

@push('scripts')
    <script>
        (function () {
            const fieldId = @json($for);
            const field = document.getElementById(fieldId);
            if (! field) return;

            const container = document.querySelector('[data-strength-meter][data-for="' + fieldId + '"]');
            if (! container) return;

            const bar = container.querySelector('[data-strength-bar]');
            const items = container.querySelectorAll('[data-strength-req]');

            function evaluate(value) {
                return {
                    length: value.length >= 8,
                    uppercase: /[A-Z]/.test(value),
                    number: /[0-9]/.test(value),
                    symbol: /[^A-Za-z0-9]/.test(value),
                };
            }

            function update() {
                const results = evaluate(field.value);
                const metCount = Object.values(results).filter(Boolean).length;

                const percent = (metCount / 4) * 100;
                bar.style.width = percent + '%';

                bar.classList.remove('bg-red-500', 'bg-amber-500', 'bg-green-600');
                if (metCount <= 1) {
                    bar.classList.add('bg-red-500');
                } else if (metCount <= 3) {
                    bar.classList.add('bg-amber-500');
                } else {
                    bar.classList.add('bg-green-600');
                }

                items.forEach(function (item) {
                    const key = item.getAttribute('data-strength-req');
                    const icon = item.querySelector('[data-strength-icon]');
                    const met = results[key];

                    if (met) {
                        item.classList.remove('text-gray-600');
                        item.classList.add('text-green-600');
                        icon.innerHTML = '&#10003;';
                        icon.classList.add('scale-110');
                    } else {
                        item.classList.remove('text-green-600');
                        item.classList.add('text-gray-600');
                        icon.innerHTML = '&#10007;';
                        icon.classList.remove('scale-110');
                    }
                });
            }

            field.addEventListener('input', update);
            update();
        })();
    </script>
@endpush
