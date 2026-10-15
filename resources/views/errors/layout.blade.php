<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - ASCOT DRRMS</title>
    @include('layouts.partials.theme')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    // Even an error page keeps the way to call for help (config/hotlines.php).
    $office = collect(\App\Support\Hotlines::groups())->flatMap(fn ($g) => $g['entries'])
        ->first(fn ($e) => $e['label'] === 'ASCOT DRRMO Office' && ! empty($e['numbers']));
    $officeNumber = $office['numbers'][0] ?? null;
@endphp
<body class="min-h-screen bg-canvas text-gray-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6 py-12" data-error-page="@yield('code')">
        <a href="{{ url('/') }}" class="text-lg font-bold tracking-tight">ASCOT<span class="text-accent-ink">-DRRMS</span></a>
        <p class="mt-10 font-display text-7xl font-semibold leading-none text-accent-ink">@yield('code')</p>
        <h1 class="mt-4 text-2xl font-semibold tracking-tight">@yield('title')</h1>
        <p class="mt-2 text-base leading-7 text-gray-600">@yield('message')</p>
        <div class="mt-8 flex flex-wrap gap-3">
            @yield('actions')
        </div>
        @if ($officeNumber)
            <div class="mt-10 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <x-icon name="emergency" class="h-6 w-6" />
                <p>Emergency? Call the ASCOT DRRMO Office: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $officeNumber) }}" class="whitespace-nowrap font-semibold underline">{{ $officeNumber }}</a></p>
            </div>
        @endif
    </main>
</body>
</html>
