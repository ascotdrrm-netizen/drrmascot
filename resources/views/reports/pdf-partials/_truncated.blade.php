{{--
    Shown only when a section hit its row cap (the caller checks 'truncated',
    i.e. total > limit), so a signed report never drops rows silently.
    $limit: the cap. $drill: true for Drill Tracker sections, which are
    campus-wide and have no school filter.
--}}
<p class="notice">Showing the first {{ number_format($limit) }} records. Narrow the period{{ ($drill ?? false) ? ' or campus' : ', campus or school' }} to see the rest.</p>
