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
