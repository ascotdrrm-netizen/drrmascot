{{-- Set the theme before anything paints (no flash): the visitor's saved
     choice, otherwise dark — see resources/css/app.css and <x-theme-toggle>. --}}
<script>
    (function () {
        var theme = null;
        try { theme = localStorage.getItem('drrms-theme'); } catch (e) {}
        document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
    })();
</script>
<meta name="theme-color" content="#0b0b0c">
<meta name="csrf-token" content="{{ csrf_token() }}">
@if (config('hosting.direct_uploads'))
    <meta name="direct-upload-url" content="{{ route('uploads.sign') }}">
@endif
@if (config('hosting.serverless') && auth()->check() && auth()->user()->is_approved && in_array(auth()->user()->role, ['DRRMO Admin', 'Coordinator'], true))
    <meta name="broadcast-work-url" content="{{ route('broadcasts.work') }}">
@endif
