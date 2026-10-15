{{--
    A photo for PDFs, scaled here (in PHP, from the image's real pixel size)
    to fit inside maxWidth x maxHeight while keeping its aspect ratio — dompdf's
    max-width/max-height support is unreliable, so explicit sizes are safer.
    $src is a base64 data URI built from the real file (see App\Support\PdfImage).
--}}
@props(['src', 'caption' => null, 'maxWidth' => 470, 'maxHeight' => 300])
@php
    $width = $maxWidth;
    $height = $maxHeight;
    $raw = base64_decode(substr($src, strpos($src, ',') + 1), true);
    $size = $raw !== false ? @getimagesizefromstring($raw) : false;
    if ($size && $size[0] > 0 && $size[1] > 0) {
        $scale = min($maxWidth / $size[0], $maxHeight / $size[1], 1);
        $width = (int) round($size[0] * $scale);
        $height = (int) round($size[1] * $scale);
    }
@endphp
<div class="photo">
    <img src="{{ $src }}" alt="{{ $caption ?? 'Photo' }}" style="width: {{ $width }}px; height: {{ $height }}px;">
    @if ($caption)
        <p class="caption">{{ $caption }}</p>
    @endif
</div>
