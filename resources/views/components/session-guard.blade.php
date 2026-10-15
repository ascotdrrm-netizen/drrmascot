@if (session('logged_out'))
    <script>
        try { localStorage.setItem('drrms-logout', String(Date.now())); } catch (_) {}
    </script>
@endif
@auth
    <script>
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) window.location.reload();
        });
        window.addEventListener('storage', function (event) {
            if (event.key === 'drrms-logout' && event.newValue) window.location.replace(@js(route('login')));
        });
    </script>
@endauth
