{{--
    Password field with a show/hide toggle. Renders the label, the input, the
    eye button and the field's validation error; anything passed in the
    default slot (e.g. the password-strength meter) renders underneath.

    Props
      name, id (defaults to name), label (omit for an aria-label-only field),
      autocomplete (current-password | new-password | off), required,
      autofocus, disabled, size (md | sm), errorKey (defaults to name),
      showError (default true)
    Slot
      labelAside   content on the right of the label row (e.g. "Forgot password?")

    Every field starts hidden (type=password) and never remembers a shown
    state: fields are forced back to hidden on submit and when a page is
    restored from the back/forward cache. Each button only controls the
    input named in its aria-controls, so fields toggle independently.
--}}
@props([
    'name',
    'id' => null,
    'label' => null,
    'autocomplete' => 'current-password',
    'required' => false,
    'autofocus' => false,
    'disabled' => false,
    'size' => 'md',
    'errorKey' => null,
    'showError' => true,
])

@php
    $id = $id ?? $name;
    $errorKey = $errorKey ?? $name;
    $hasError = $showError && $errors->has($errorKey);
    // Thesis panel (IT): a password can't be copied out of its field, and the
    // confirmation must be typed, not pasted, so it really confirms the password.
    $noPaste = str_ends_with($name, '_confirmation');
    $sizeClasses = $size === 'sm'
        ? 'min-h-[2.75rem] rounded-lg pl-4 pr-11 text-xs sm:min-h-[2rem]'
        : 'min-h-[2.75rem] rounded-lg pl-4 pr-11 text-sm sm:min-h-[2.5rem]';
    $stateClasses = $hasError
        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
        : 'border-gray-300 focus:border-accent-line focus:ring-accent-line';
@endphp

@if ($label)
    @isset($labelAside)
        <div class="flex items-center justify-between gap-4">
            <label for="{{ $id }}" class="form-label">{{ $label }}</label>
            {{ $labelAside }}
        </div>
    @else
        <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    @endisset
@endif

<div class="relative">
    <input type="password" id="{{ $id }}" name="{{ $name }}" autocomplete="{{ $autocomplete }}"
           data-password-field
           @if ($noPaste) data-no-paste @endif
           @if ($required) required @endif
           @if ($autofocus) autofocus @endif
           @if ($disabled) disabled @endif
           @unless ($label) aria-label="{{ $attributes->get('aria-label', \Illuminate\Support\Str::headline($name)) }}" @endunless
           @if ($hasError) aria-invalid="true" @endif
           @if ($hasError || $noPaste) aria-describedby="{{ trim(($hasError ? $id . '-error ' : '') . ($noPaste ? $id . '-no-paste' : '')) }}" @endif
           {{ $attributes->except('aria-label')->merge(['class' => "block w-full border {$sizeClasses} {$stateClasses} focus:outline-none focus:ring-2 disabled:bg-gray-100"]) }}>

    <button type="button" data-password-toggle aria-controls="{{ $id }}" aria-label="Show password" aria-pressed="false"
            @if ($disabled) disabled @endif
            class="absolute inset-y-0 right-0 flex {{ $size === 'sm' ? 'w-10' : 'w-11' }} items-center justify-center rounded-r-lg text-gray-600 hover:text-gray-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-line disabled:cursor-not-allowed disabled:text-gray-300">
        <x-icon name="visibility" class="h-5 w-5" data-icon="eye" />
        <x-icon name="visibility_off" class="hidden h-5 w-5" data-icon="eye-off" />
    </button>
</div>

@if ($noPaste)
    <p id="{{ $id }}-no-paste" class="form-help">Type it again: pasting is turned off here.</p>
@endif

@if ($hasError)
    <p id="{{ $id }}-error" class="form-error">{{ $errors->first($errorKey) }}</p>
@endif

{{ $slot }}

@once
    @push('scripts')
        <script>
            // Show/hide toggle for every password field (event delegation: one
            // listener serves all fields on the page, each button controls only
            // the input named in its aria-controls).
            (function () {
                function setShown(button, input, shown) {
                    input.type = shown ? 'text' : 'password';
                    button.setAttribute('aria-pressed', shown ? 'true' : 'false');
                    button.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
                    button.querySelector('[data-icon="eye"]').classList.toggle('hidden', shown);
                    button.querySelector('[data-icon="eye-off"]').classList.toggle('hidden', ! shown);
                }

                function hideAll(root) {
                    root.querySelectorAll('[data-password-toggle]').forEach(function (button) {
                        var input = document.getElementById(button.getAttribute('aria-controls'));
                        if (input) setShown(button, input, false);
                    });
                }

                document.addEventListener('click', function (e) {
                    var button = e.target.closest('[data-password-toggle]');
                    if (! button || button.disabled) return;
                    var input = document.getElementById(button.getAttribute('aria-controls'));
                    if (! input) return;
                    setShown(button, input, input.type === 'password');
                });

                // Never submit (or let the browser store) a password as plain text.
                document.addEventListener('submit', function (e) { hideAll(e.target); }, true);

                // Passwords never leave their field by copy or cut (even while shown),
                // and the confirmation field takes no paste.
                ['copy', 'cut'].forEach(function (type) {
                    document.addEventListener(type, function (e) {
                        if (e.target.closest && e.target.closest('[data-password-field]')) e.preventDefault();
                    });
                });
                document.addEventListener('paste', function (e) {
                    if (e.target.closest && e.target.closest('[data-no-paste]')) e.preventDefault();
                });
                document.addEventListener('drop', function (e) {
                    if (e.target.closest && e.target.closest('[data-no-paste]')) e.preventDefault();
                });

                // A page restored from the back/forward cache starts hidden again.
                window.addEventListener('pageshow', function () { hideAll(document); });
            })();
        </script>
    @endpush
@endonce
