{{--
    "Latest Updates" is the ASCOT DRRMO's own public Facebook Page, embedded
    live via Facebook's Page Plugin (fb-page widget) — not a locally stored
    feed. There is no local database content here to filter for public/
    internal data: the widget only ever renders whatever is already public
    on that Facebook Page, verbatim, the same as anyone visiting it directly.
    Requires #fb-root + the Facebook SDK script (loaded once in landing.blade.php).

    Facebook's Page Plugin caps data-width at 500px (min 180px) and cannot
    be stretched wider with CSS, and data-adapt-container-width only sizes
    the widget on its INITIAL load — it does not react to the window being
    resized or the phone being rotated afterwards. The script below handles
    both: it sets data-width/data-height from the container's real size
    before the SDK ever parses this element (see the ordering note below),
    and re-applies them on resize/orientation change.
--}}
<div class="w-full max-w-[500px] mx-auto" id="fb-feed-container">
    <div class="fb-page"
         id="fb-feed-widget"
         data-href="https://www.facebook.com/profile.php?id=61579337100805"
         data-tabs="timeline"
         data-width="500"
         data-height="800"
         data-small-header="false"
         data-adapt-container-width="true"
         data-hide-cover="false"
         data-show-facepile="true">
    </div>
</div>

{{--
    Deliberately a plain synchronous inline script (no defer/async, no
    DOMContentLoaded wrapper) placed immediately after the fb-page div.
    The Facebook SDK script tag further down the page is loaded with
    async+defer, so it can only start executing after a network fetch —
    this inline script runs instantly as the parser reaches it, guaranteeing
    the correct data-width/data-height are already on the element before
    the SDK's own automatic XFBML parse ever sees it. No race condition.
--}}
<script>
    (function () {
        var container = document.getElementById('fb-feed-container');
        var widget = document.getElementById('fb-feed-widget');
        if (! container || ! widget) {
            return;
        }

        // Pristine copy of the element as authored. Once the SDK renders an
        // fb-page it marks it processed (fb_iframe_widget class,
        // fb-xfbml-state attribute, injected iframe) and a later
        // FB.XFBML.parse() skips it — so a re-render has to swap in a fresh,
        // unprocessed copy first.
        var pristineWidget = widget.cloneNode(false);

        var lastWidth = null;
        var lastHeight = null;

        function heightForViewport() {
            var w = window.innerWidth;
            if (w < 640) return 600;   // phones
            if (w < 1024) return 700;  // tablets
            return 800;                // laptops/desktops
        }

        function widthForContainer() {
            var measured = Math.round(container.getBoundingClientRect().width) || 500;
            return Math.max(180, Math.min(500, measured));
        }

        function applySize() {
            var width = widthForContainer();
            var height = heightForViewport();

            widget.setAttribute('data-width', String(width));
            widget.setAttribute('data-height', String(height));

            return { width: width, height: height, changed: width !== lastWidth || height !== lastHeight };
        }

        // Initial sizing — runs before the SDK has had any chance to parse
        // this element (see comment above), so the very first render already
        // uses the real container width instead of the 500/800 placeholders.
        var initial = applySize();
        lastWidth = initial.width;
        lastHeight = initial.height;

        var resizeTimer = null;

        function handleResize() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                var result = applySize();

                // Skip re-rendering entirely if nothing actually changed —
                // avoids needless reload loops from unrelated resize events
                // (e.g. mobile browser chrome show/hide on scroll).
                if (! result.changed) {
                    return;
                }

                lastWidth = result.width;
                lastHeight = result.height;

                // Guard against the SDK not being loaded yet (e.g. blocked by
                // an ad blocker, or the very first resize firing before the
                // async script has finished fetching) — nothing to re-render
                // in that case, and the updated attributes above will already
                // be correct whenever/if the SDK does eventually parse it.
                if (window.FB && window.FB.XFBML && typeof window.FB.XFBML.parse === 'function') {
                    var fresh = pristineWidget.cloneNode(false);
                    fresh.setAttribute('data-width', String(result.width));
                    fresh.setAttribute('data-height', String(result.height));
                    widget.parentNode.replaceChild(fresh, widget);
                    widget = fresh;
                    window.FB.XFBML.parse(container);
                }
            }, 300);
        }

        window.addEventListener('resize', handleResize);
        window.addEventListener('orientationchange', handleResize);
    })();
</script>
