{{-- "Download PDF": a button with a download icon, so it reads as a download
     and not as text. small = inside a table row or card footer. The confirm
     message (data-confirm) is shown by components/confirm-dialog. --}}
@props(['href', 'confirm' => null, 'small' => false, 'label' => 'Download PDF'])
<a href="{{ $href }}" @if ($confirm) data-confirm="{{ $confirm }}" @endif data-download-pdf
   {{ $attributes->merge(['class' => 'btn btn-secondary' . ($small ? ' btn-sm' : '')]) }}>
    <x-icon name="download" class="{{ $small ? 'h-4 w-4' : 'h-5 w-5' }} shrink-0" />
    <span>{{ $label }}</span>
</a>
