{{--
    The button row at the bottom of every app form — one order everywhere:
    secondary (e.g. Cancel) first, primary last, so the primary sits on the
    right. On phones the buttons stack full-width with the primary on top.

    Usage:
      (x-form-actions cancel="URL")
          <button type="submit" class="btn btn-primary">Save</button>
      (/x-form-actions)
--}}
@props(['cancel' => null, 'cancelLabel' => 'Cancel'])

<div {{ $attributes->merge(['class' => 'flex flex-col-reverse gap-4 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-end']) }}>
    @if ($cancel)
        <a href="{{ $cancel }}" class="btn btn-secondary">{{ $cancelLabel }}</a>
    @endif
    {{ $slot }}
</div>
