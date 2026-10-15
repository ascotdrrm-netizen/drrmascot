<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No signal - ASCOT DRRM</title>
    @include('layouts.partials.theme')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-gray-900 antialiased">
    {{-- Shown by public/sw.js when a page is not saved on this phone and there is no signal. --}}
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 text-center">
        <x-icon name="wifi_off" class="mx-auto h-12 w-12 text-gray-500" />
        <h1 class="mt-4 page-title">No signal</h1>
        <p class="mt-2 text-sm text-gray-600">This page isn’t saved on your phone yet. Emergency hotlines still work without internet.</p>
        <a href="{{ url('/app/hotlines') }}" class="btn btn-primary mt-6"><x-icon name="call" /> Emergency hotlines</a>
        <button type="button" onclick="location.reload()" class="btn btn-secondary mt-3">Try again</button>
    </main>
</body>
</html>
